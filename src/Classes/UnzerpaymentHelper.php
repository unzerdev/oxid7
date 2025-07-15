<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Classes;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Core\Di\ContainerFacade;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\QueryBuilderFactoryInterface;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;
use OxidEsales\EshopCommunity\Internal\Transition\Adapter\TemplateLogic\FormatPriceLogic;
use Unzer\UnzerPayment\Classes\Payment;
use Unzer\UnzerPayment\Constants\Constants;
use UnzerSDK\Resources\TransactionTypes\AbstractTransactionType;
use UnzerSDK\Resources\TransactionTypes\Authorization;
use UnzerSDK\Resources\TransactionTypes\Cancellation;
use UnzerSDK\Resources\TransactionTypes\Charge;
use UnzerSDK\Resources\TransactionTypes\Chargeback;


class UnzerpaymentHelper

{
    /**
     * @var UnzerpaymentHelper
     */
    protected static $oInstance = null;

    protected static $lastPaymentCurrency = null;

    /**
     * Create singleton instance of payment helper
     *
     * @return Payment
     */
    static function getInstance()
    {
        if (self::$oInstance === null) {
            self::$oInstance = oxNew(self::class);
        }
        return self::$oInstance;
    }

    /**
     * @return string
     */
    public function getNotifyUrl()
    {
        return Registry::getConfig()->getCurrentShopUrl(false).'index.php?cl=unzer_payment_webhook&fnc=notify';
    }

    /**
     * Returns specific  Language
     *
     * @return string
     */
    public function getUnzerLanguage()
    {
        $current_lang_id = Registry::getLang()->getBaseLanguage();
        $current_lang_array = Registry::getLang()->getLanguageArray($current_lang_id, true);
        return $current_lang_array[$current_lang_id]->abbr . '_' . strtoupper($current_lang_array[$current_lang_id]->abbr);
    }

    /**
     * @param $sPaymentId
     * @return bool
     */
    public function isUnzerPaymentMethod($sPaymentId)
    {
        if (substr($sPaymentId, 0, strlen(Constants::PAYMENT_METHOD_PREFIX)) != Constants::PAYMENT_METHOD_PREFIX) {
            return false;
        }
        return array_key_exists(substr($sPaymentId, strlen(Constants::PAYMENT_METHOD_PREFIX)), Constants::PAYMENT_METHODS);
    }

    /**
     * @param $sPaymentId
     * @return bool
     */
    public function isUnzerLegacyPaymentMethod($sPaymentId)
    {
        return strpos($sPaymentId, 'oscunzer') !== false;
    }

    /**
     * @param $sPaymentId
     * @return mixed
     */
    public function getUnzerPaymentMethodModel($sPaymentId)
    {
        $oPaymentModel = oxNew(
            'Unzer\UnzerPayment\PaymentMethods\\' .
            Constants::PAYMENT_METHODS[substr($sPaymentId, strlen(Constants::PAYMENT_METHOD_PREFIX))]['class_name']
        );
        return $oPaymentModel;
    }

    /**
     * Check for birthDate validity
     *
     * @param string $date birthdate to validate
     * @return boolean Validity is ok or not
     */
    public function isBirthDate($date)
    {
        if (empty($date))
            return true;
        if (preg_match('/^([0-9]{4})-((0?[1-9])|(1[0-2]))-((0?[1-9])|([1-2][0-9])|(3[01]))( [0-9]{2}:[0-9]{2}:[0-9]{2})?$/ui', $date, $birthDate)) {
            if ($birthDate[1] >= date('Y') - 9)
                return false;
            return true;
        }
        return false;
    }

    /**
     * @param $number
     * @return string
     */
    public function prepareAmountValue($number)
    {
        return (float)number_format($number, 2, '.', '');
    }

    /**
     * @param $sDeliveryAddressMD5
     * @return string
     */
    protected function getAdditionalParameters($sDeliveryAddressMD5)
    {
        $oRequest = Registry::getRequest();
        $oSession = Registry::getSession();

        $sAddParams = '';

        $sSid = $oSession->sid(true);
        if ($sSid != '') {
            $sAddParams .= '&'.$sSid;
        }

        if (!$oRequest->getRequestEscapedParameter('stoken')) {
            $sAddParams .= '&stoken='.$oSession->getSessionChallengeToken();
        }
        $sAddParams .= '&ord_agb=1';
        $sAddParams .= '&rtoken='.$oSession->getRemoteAccessToken();

        $sAddParams .= '&sDeliveryAddressMD5='.$sDeliveryAddressMD5;

        return $sAddParams;
    }

    /**
     * @param $sDeliveryAddressMD5
     * @return string
     */
    public function getRedirectUrl($sDeliveryAddressMD5)
    {
        $sBaseUrl = Registry::getConfig()->getCurrentShopUrl().'index.php?cl=order&fnc=unzerPayment';

        return $sBaseUrl.$this->getAdditionalParameters($sDeliveryAddressMD5);
    }

    /**
     * @param $state
     * @return bool
     */
    public function isValidState($state)
    {
        return in_array(
            $state,
            [
                \UnzerSDK\Constants\TransactionStatus::STATUS_SUCCESS,
                \UnzerSDK\Constants\TransactionStatus::STATUS_PENDING
            ]
        );
    }

    /**
     * @param $orderId
     * @param $status
     * @return void
     * @throws \OxidEsales\Eshop\Core\Exception\DatabaseConnectionException
     * @throws \OxidEsales\Eshop\Core\Exception\DatabaseErrorException
     */
    public function setOrderStatus($orderId, $status)
    {
        $oDb = \OxidEsales\Eshop\Core\DatabaseProvider::getDb();
        $sQ = 'update oxorder set oxtransstatus = :oxtransstatus where oxid = :oxid';
        $oDb->execute($sQ, [
            ':oxtransstatus' => $status,
            ':oxid' => $orderId
        ]);
    }

    /**
     * @param $orderId
     * @return void
     * @throws \OxidEsales\Eshop\Core\Exception\DatabaseConnectionException
     * @throws \OxidEsales\Eshop\Core\Exception\DatabaseErrorException
     */
    public function setOrderPaid($orderId)
    {
        $oDb = \OxidEsales\Eshop\Core\DatabaseProvider::getDb();
        $sQ = 'update oxorder set oxpaid = now() where oxid = :oxid';
        $oDb->execute($sQ, [
            ':oxid' => $orderId
        ]);
    }

    /**
     * @param $transactionId
     * @return mixed
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function getOrderIdByTransactionId($transactionId)
    {
        $container = ContainerFactory::getInstance()->getContainer();
        $queryBuilderFactory = $container->get(QueryBuilderFactoryInterface::class);
        $queryBuilder = $queryBuilderFactory->create();
        $queryBuilder
            ->select('oxid')
            ->from('oxorder')
            ->where('oxtransid = :transactionId')
            ->setParameter('transactionId', $transactionId);
        $orderId = $queryBuilder->execute()->fetchColumn();
        return $orderId;
    }

    /**
     * @param $paymentMethod
     * @return string
     */
    public function getPaymentMethodChargeMode($paymentMethod)
    {
        $configKey = 'UnzerPayment' . ucfirst($paymentMethod::UNZER_LONG_CODE) . 'ChargeMode';
        $moduleSettingService = ContainerFacade::get(ModuleSettingServiceInterface::class);
        if ($moduleSettingService->exists($configKey, Constants::MODULE_ID)) {
            return $moduleSettingService->getString($configKey, Constants::MODULE_ID);
        }
        return '';
    }

    /**
     * @param $paymenttype
     * @param $transaction_id
     * @param $order
     * @return array|void
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function getLegacyTransactions($paymenttype, $transaction_id, $order)
    {
        $unzer = $this->buildLegacyClient(
            $paymenttype,
            $order
        );

        if ($unzer) {
            $paymentId = $this->getLegacyPaymentId(
                $order,
                $transaction_id
            );

            if ($paymentId) {
                return $this->getTransactions(
                    $paymentId,
                    $order,
                    $unzer
                );
            }
        }
        return null;
    }

    /**
     * @param $order
     * @param $transaction_id
     * @return mixed|null
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function getLegacyPaymentId($order, $transaction_id)
    {
        $container = ContainerFactory::getInstance()->getContainer();
        $queryBuilderFactory = $container->get(QueryBuilderFactoryInterface::class);
        $queryBuilder = $queryBuilderFactory->create();
        $queryBuilder
            ->select('typeid')
            ->from('oscunzertransaction')
            ->where('oxorderid = :oxorderid')
            ->andWhere('shortid = :shortid')
            ->setParameter('oxorderid', $order->getId())
            ->setParameter('shortid', $transaction_id);
        $legacyData = $queryBuilder->execute()->fetchAll();
        if (is_array($legacyData) && isset($legacyData[0]['typeid'])) {
            return $legacyData[0]['typeid'];
        }
        return null;
    }

    /**
     * @param $paymenttype
     * @param $order
     * @return mixed|UnzerpaymentClient|null
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function buildLegacyClient($paymenttype, $order)
    {
        $container = ContainerFactory::getInstance()->getContainer();
        $queryBuilderFactory = $container->get(QueryBuilderFactoryInterface::class);
        $queryBuilder = $queryBuilderFactory->create();
        $queryBuilder
            ->select('oxid, currency, customertype')
            ->from('oscunzertransaction')
            ->where('oxorderid = :oxorderid')
            ->setParameter('oxorderid', $order->getId());
        $legacyData = $queryBuilder->execute()->fetchAll();

        if (is_array($legacyData) && isset($legacyData[0])) {
            $unzer = UnzerpaymentClient::getLegacyInstance(
                $paymenttype,
                $legacyData[0]['currency'],
                $legacyData[0]['customertype']
            );
            if ($unzer) {
                return $unzer;
            }
        }
        return null;
    }

    /**
     * @param $payment_id
     * @param $order
     * @param $unzer
     * @return array
     */
    public function getTransactions($payment_id, $order, $unzer = null)
    {
        if (is_null($unzer)) {
            $unzer = UnzerpaymentClient::getInstance();
        }
        try {
            $payment = $unzer->fetchPayment($payment_id);
        } catch (\Exception $e) {
            return [];
        }

        $currency = $payment->getCurrency();
        self::$lastPaymentCurrency = $currency;
        $transactions = array();
        if ( $payment->getAuthorization() ) {
            $transactions[] = $payment->getAuthorization();
            if ( $payment->getAuthorization()->getCancellations() ) {
                $transactions = array_merge( $transactions, $payment->getAuthorization()->getCancellations() );
            }
        }
        if ( $payment->getCharges() ) {
            foreach ( $payment->getCharges() as $charge ) {
                $transactions[] = $charge;
                if ( $charge->getCancellations() ) {
                    $transactions = array_merge( $transactions, $charge->getCancellations() );
                }
            }
        }
        if ( $payment->getReversals() ) {
            foreach ( $payment->getReversals() as $reversal ) {
                $transactions[] = $reversal;
            }
        }
        if ( $payment->getRefunds() ) {
            foreach ( $payment->getRefunds() as $refund ) {
                $transactions[] = $refund;
            }
        }
        if ( $payment->getChargebacks() ) {
            foreach ( $payment->getChargebacks() as $chargeback ) {
                $transactions[] = $chargeback;
            }
        }
        $transactionTypes = array(
            Cancellation::class  => 'cancellation',
            Chargeback::class    => 'chargeback',
            Charge::class        => 'charge',
            Authorization::class => 'authorization',
        );
        $transactions = array_map(
            function ( AbstractTransactionType $transaction ) use ( $transactionTypes, $currency ) {
                $return         = $transaction->expose();
                $class          = get_class( $transaction );
                $return['type'] = $transactionTypes[ $class ] ?? $class;
                $return['time'] = $transaction->getDate();
                if ( $return['type'] != 'chargeback' && method_exists( $transaction, 'getAmount' ) && method_exists( $transaction, 'getCurrency' ) ) {
                    $return['amount'] = self::displayPrice( $transaction->getAmount(), $transaction->getCurrency() );
                } elseif ( isset( $return['amount'] ) ) {
                    $return['amount'] = self::displayPrice( $return['amount'], $currency );
                }
                $status           = $transaction->isSuccess() ? 'success' : 'error';
                $status           = $transaction->isPending() ? 'pending' : $status;
                $return['status'] = $status;

                return $return;
            },
            $transactions
        );
        usort(
            $transactions,
            function ( $a, $b ) {
                return strcmp( $a['time'], $b['time'] );
            }
        );
        $data = array(
            'id'                => $payment->getId(),
            'paymentMethod'     => $order->payment,
            'cartID'            => $order->id_cart,
            'paymentBaseMethod' => \UnzerSDK\Services\IdService::getResourceTypeFromIdString($payment->getPaymentType()->getId()),
            'shortID'           => $payment->getInitialTransaction()->getShortId(),
            'currency'          => $payment->getAmount()->getCurrency(),
            'amount'            => self::displayPrice( $payment->getAmount()->getTotal(), $payment->getAmount()->getCurrency() ),
            'amountPlain'       => $payment->getAmount()->getTotal(),
            'charged'           => self::displayPrice( $payment->getAmount()->getCharged(), $payment->getAmount()->getCurrency() ),
            'chargedPlain'      => $payment->getAmount()->getCharged(),
            'cancelled'         => self::displayPrice( $payment->getAmount()->getCanceled(), $payment->getAmount()->getCurrency() ),
            'cancelledPlain'    => $payment->getAmount()->getCanceled(),
            'remaining'         => self::displayPrice( $payment->getAmount()->getRemaining(), $payment->getAmount()->getCurrency() ),
            'remainingPlain'    => $payment->getAmount()->getRemaining(),
            'canCancel'         => $payment->getAmount()->getCanceled() != $payment->getAmount()->getTotal() && $payment->getAmount()->getRemaining() > 0,
            'transactions'      => $transactions,
            'status'            => $payment->getStateName(),
            'raw'               => print_r( $payment, true ),
        );
        return $data;
    }

    /**
     * @param $price
     * @param $currency
     * @return string
     */
    public static function displayPrice($price, $currency)
    {
        if ($currency == '') {
            $currency = self::$lastPaymentCurrency;
        }
        if (class_exists('NumberFormatter')) {
            $formatter = new \NumberFormatter(self::getPreferredLocale($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), \NumberFormatter::CURRENCY);
            $formatted = $formatter->formatCurrency($price, $currency);
            return $formatted;
        }
        return number_format($price, 2) . ' ' . $currency;
    }

    /**
     * @param string $acceptLanguage
     * @return string
     */
    public static function getPreferredLocale(string $acceptLanguage): string {
        if (preg_match('/^[a-zA-Z\-]+/', $acceptLanguage, $matches)) {
            return str_replace('-', '_', $matches[0]);
        }
        return 'de_DE'; // Fallback
    }



}