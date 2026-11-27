<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Model;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Core\Field;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Core\Di\ContainerFacade;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;
use OxidEsales\EshopCommunity\Internal\Framework\Templating\TemplateRendererBridgeInterface;
use Unzer\UnzerPayment\Classes\UnzerpaymentClient;
use Unzer\UnzerPayment\Classes\UnzerpaymentHelper;
use Unzer\UnzerPayment\Constants\Constants;
use Unzer\UnzerPayment\Controller\ThankYouController;
use Unzer\UnzerPayment\PaymentMethods\UnzerApplepayPaymentMethod;
use Unzer\UnzerPayment\PaymentMethods\UnzerGooglepayPaymentMethod;
use Unzer\UnzerPayment\PaymentMethods\UnzerPaypalPaymentMethod;
use Unzer\UnzerPayment\Service\BasketService;
use Unzer\UnzerPayment\Service\CustomerService;
use Unzer\UnzerPayment\Service\DebugHandler;
use Unzer\UnzerPayment\Service\ExpressCheckoutService;
use Unzer\UnzerPayment\Service\PaymentService;
use Unzer\UnzerPayment\Service\RedirectSessionService;
use Unzer\UnzerPayment\Traits\ServiceContainer;
use UnzerSDK\Resources\TransactionTypes\Authorization;
use UnzerSDK\Resources\TransactionTypes\Charge;


class PaymentGateway extends PaymentGateway_parent
{
    use ServiceContainer;

    public const SESSION_REDIRECT_REQUIRED = RedirectSessionService::SESSION_REQUIRED;
    public const SESSION_REDIRECT_URL = RedirectSessionService::SESSION_URL;
    public const SESSION_REDIRECT_ORDER_ID = RedirectSessionService::SESSION_ORDER_ID;
    public const SESSION_REDIRECT_PAYMENT_ID = RedirectSessionService::SESSION_PAYMENT_ID;

    /**
     * @param $dAmount
     * @param $oOrder
     * @return bool
     */
    public function executePayment($dAmount, &$oOrder)
    {
        $logger = $this->getServiceFromContainer(DebugHandler::class);
        $this->clearRedirectSession();
        if (!UnzerpaymentHelper::getInstance()->isUnzerPaymentMethod($oOrder->oxorder__oxpaymenttype->value)) {
            return parent::executePayment($dAmount, $oOrder);
        }

        $session = Registry::getSession();
        if ($session->getVariable(ExpressCheckoutService::SESSION_PAYPAL_PAYMENT_ID)) {
            try {
                $transaction = $this->payPaypalExpress(
                    $session->getVariable(ExpressCheckoutService::SESSION_PAYPAL_PAYMENT_ID),
                    $oOrder
                );
            } catch (\Throwable $e) {
                $logger->addLog('PaymentGateway::executePayment()', 3, false, ['Paypal Express', $e->getMessage()]);
                $this->_sLastError = $e->getClientMessage();
                ThankYouController::resetExpressCheckoutSessionData();
                return false;
            }
        } elseif ($session->getVariable(ExpressCheckoutService::SESSION_GOOGLE_PAYMENT_TYPE_ID)) {
            try {
                $googlePayId = $session->getVariable(ExpressCheckoutService::SESSION_GOOGLE_PAYMENT_TYPE_ID);
                $transaction =  $this->payGoogleExpress(
                    $googlePayId,
                    $oOrder
                );
            } catch (\Throwable $e) {
                $logger->addLog('PaymentGateway::executePayment()', 3, false, ['Google Pay Express', $e->getMessage()]);
                $this->_sLastError = $e->getClientMessage();
                ThankYouController::resetExpressCheckoutSessionData();
                return false;
            }
        } elseif ($session->getVariable(ExpressCheckoutService::SESSION_APPLEPAY_PAYMENT_TYPE_ID)) {
            try {
                $applePayId = $session->getVariable(ExpressCheckoutService::SESSION_APPLEPAY_PAYMENT_TYPE_ID);
                $transaction = $this->payApplePayExpress(
                    $applePayId,
                    $oOrder
                );
            } catch (\Throwable $e) {
                $logger->addLog('PaymentGateway::executePayment()', 3, false, ['Apple Pay Express', $e->getMessage()]);
                $this->_sLastError = $e->getClientMessage();
                ThankYouController::resetExpressCheckoutSessionData();
                return false;
            }
        }
        if (isset($transaction)) {
            return true;
        }

        $this->handleUnzerPayment($oOrder, $dAmount);
        $this->resetSession();
        return true;
    }

    private function beforeRedirect($transaction, $oOrder)
    {
        $oOrder->oxorder__oxtransid = new Field($transaction->getPayment()->getId(), Field::T_RAW);
        if (Registry::getSession()->getVariable(ExpressCheckoutService::SESSION_SELECTED_EXPRESS_METHOD)) {
            $oOrder->oxorder__oxunzerpayordernr = new Field((string) $oOrder->getId(), Field::T_RAW);
            $oOrder->oxorder__oxunzerexpressmethod =  new Field(
                1,
                Field::T_RAW
            );
        }
        $oOrder->save();
        $this->resetSession();
    }

    private function clearRedirectSession(): void
    {
        (new RedirectSessionService())->clear();
    }

    /**
     * @param $oOrder
     * @param $dAmount
     * @return true
     * @throws \UnzerSDK\Exceptions\UnzerApiException
     */
    public function handleUnzerPayment($oOrder, $dAmount)
    {
        $logger = $this->getServiceFromContainer(DebugHandler::class);
        $session = Registry::getSession();
        $UnzerMetadataId = $session->getVariable('UnzerMetadataId');
        $UnzerPaypageId = $session->getVariable('UnzerPaypageId');
        $paypage = UnzerpaymentClient::getInstance()->fetchPaypageV2($UnzerPaypageId);
        $payment = $paypage->getPayments()[0];
        $transaction_id = $payment->getPaymentId();

        /* @var $oOrder \OxidEsales\EshopCommunity\Application\Model\Order */
        $oOrder->oxorder__oxtransid = new \OxidEsales\Eshop\Core\Field($transaction_id, \OxidEsales\Eshop\Core\Field::T_RAW);
        $oOrder->save();

        $metadata = UnzerpaymentClient::getInstance()->fetchMetadata(
            $UnzerMetadataId
        );
        $metadata->addMetadata(
            'shopOrderId', $oOrder->getId()
        );

        if (UnzerpaymentHelper::getInstance()->isUnzerPaymentMethod($oOrder->oxorder__oxpaymenttype->value)) {
            $payment = UnzerpaymentClient::getInstance()->fetchPayment(
                $oOrder->oxorder__oxtransid->value
            );
            $paymentId = (\UnzerSDK\Services\IdService::getResourceTypeFromIdString($payment->getPaymentType()->getId()));
            if ($paymentId == 'ppy' || $paymentId == 'piv' || $paymentId == 'ivc') {
                try {
                    $container = ContainerFactory::getInstance()->getContainer();
                    $renderer = $container->get(TemplateRendererBridgeInterface::class)->getTemplateRenderer();

                    $params = [];
                    $params['unzer_amount'] = $payment->getInitialTransaction()->getAmount();
                    $params['unzer_currency'] = $payment->getInitialTransaction()->getCurrency();
                    $params['unzer_account_holder'] = $payment->getInitialTransaction()->getHolder();
                    $params['unzer_account_iban'] = $payment->getInitialTransaction()->getIban();
                    $params['unzer_account_bic'] = $payment->getInitialTransaction()->getBic();
                    $params['unzer_account_descriptor'] = $payment->getInitialTransaction()->getDescriptor();
                    Registry::getSession()->setVariable('additionalUnzerPaymentInformation', $renderer->renderTemplate('@' . Constants::MODULE_ID . '/_inc/transfer_data', $params));
                    $logger->addLog('setting additionalUnzerPaymentInformation', 3, false, [Registry::getSession()->getVariable('additionalUnzerPaymentInformation')]);
                } catch (\Exception $e) {
                    $logger->addLog('setting additionalUnzerPaymentInformation', 1, $e, [$params]);
                }
            }
        }

        $logger->addLog('Trying to set metadata', 3, false, [$metadata]);
        try {
            UnzerpaymentClient::getInstance()->getResourceService()->updateResource(
                $metadata
            );
        } catch (\Exception $e) {
            $logger->addLog('Could not update metadata', 1, $e, [$metadata]);
        }

        return true;
    }

    /**
     * @param $unzer_payment_id
     * @param Order $order
     * @throws \UnzerSDK\Exceptions\UnzerApiException
     */
    protected function payPaypalExpress($unzer_payment_id, $order)
    {
        $logger = $this->getServiceFromContainer(DebugHandler::class);
        $order_id = $order->getId();

        $payment = UnzerpaymentClient::getInstance()->fetchPayment($unzer_payment_id);
        $basket = ( new BasketService())->getBasket(Registry::getSession()->getVariable('expressBasket'), $order_id);
        $basket->setId($payment->getBasket()->getId());
        $basket->setOrderId($order->getId());
        UnzerpaymentClient::getInstance()->updateBasket($basket);

        ( new CustomerService() )->updatePaypalExpressCustomer( $order,  $payment->getCustomer() );

        $moduleSettingService = ContainerFacade::get(ModuleSettingServiceInterface::class);
        $bookingMode = (new UnzerpaymentHelper())->getPaymentMethodChargeMode(UnzerPaypalPaymentMethod::class);
        if ($bookingMode == '') {
            $bookingMode = $moduleSettingService->getString('UnzerPaymentMode', Constants::MODULE_ID) == '0' ?
                'authorize' : 'charge';
        }

        if (empty($payment->getCharges()) || $bookingMode === 'authorize') {
            $authorization = new Authorization(
                $basket->getTotalValueGross(),
                $basket->getCurrencyCode()
            );
            $authorization->setOrderId($order_id);
            $logger->addLog('Trying Update Authorization', 1, false, [$authorization]);
            $transaction = UnzerpaymentClient::getInstance()->updateAuthorization($payment->getId(), $authorization);
        } else {
            $charge = new Charge(
                $basket->getTotalValueGross(),
                $basket->getCurrencyCode()
            );
            $charge->setOrderId($order_id);
            $logger->addLog('Trying Update Charge', 1, false, [$payment->getId(), $charge]);
            $transaction = UnzerpaymentClient::getInstance()->updateCharge($payment->getId(), $charge);
        }
        $this->beforeRedirect($transaction, $order);
        $session = Registry::getSession();
        $session->setVariable(
            ExpressCheckoutService::SESSION_PAYMENT_ID,
            $transaction->getPaymentId()
        );
        $this->storeRedirectState($transaction, $order);
        return $transaction;
    }

    protected function payApplePayExpress($unzer_payment_id, $order)
    {
        return $this->genericPayExpress(
            UnzerApplepayPaymentMethod::class,
            $unzer_payment_id,
            $order
        );
    }

    protected function payGoogleExpress($unzer_payment_id, $order)
    {
        return $this->genericPayExpress(
            UnzerGooglepayPaymentMethod::class,
            $unzer_payment_id,
            $order
        );
    }

    protected function genericPayExpress($paymentMethodClass, $unzer_payment_id, $order)
    {
        $moduleSettingService = ContainerFacade::get(ModuleSettingServiceInterface::class);
        $bookingMode = (new UnzerpaymentHelper())->getPaymentMethodChargeMode($paymentMethodClass);
        if ($bookingMode == '') {
            $bookingMode = $moduleSettingService->getString('UnzerPaymentMode', Constants::MODULE_ID) == '0' ?
                'authorize' : 'charge';
        }

        if ( $bookingMode == 'authorize' ) {
            $transaction = ( new PaymentService() )->performAuthorizationForOrder( $order, $this, $unzer_payment_id );
        } else {
            $transaction = ( new PaymentService() )->performChargeForOrder( $order, $this, $unzer_payment_id );
        }
        $logger = $this->getServiceFromContainer(DebugHandler::class);
        $logger->addLog('Transaction', 3,false, [$paymentMethodClass, $bookingMode, $unzer_payment_id, $transaction]);

        $this->beforeRedirect($transaction, $order);
        $session = Registry::getSession();
        $session->setVariable(
            ExpressCheckoutService::SESSION_PAYMENT_ID,
            $transaction->getPaymentId()
        );
        $this->storeRedirectState($transaction, $order);

        return $transaction;
    }

    private function storeRedirectState($transaction, $order): void
    {
        $redirectUrl = $transaction->getPayment()->getRedirectUrl();
        if (!$redirectUrl) {
            return;
        }

        (new RedirectSessionService())->store(
            $redirectUrl,
            (string) $order->getId(),
            (string) $transaction->getPaymentId()
        );
    }

    /**
     * @return void
     */
    public function resetSession()
    {
        $session = Registry::getSession();
        $session->deleteVariable('expressBasket');
        $session->deleteVariable('UnzerSelectedPaymentMethod');
    }


}