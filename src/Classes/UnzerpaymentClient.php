<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Classes;

use OxidEsales\EshopCommunity\Core\Di\ContainerFacade;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;
use Unzer\UnzerPayment\Constants\Constants;
use Unzer\UnzerPayment\Service\DebugHandler;
use Unzer\UnzerPayment\Traits\ServiceContainer;
use UnzerSDK\Resources\TransactionTypes\Charge;

class UnzerpaymentClient extends \UnzerSDK\Unzer {

    use ServiceContainer;
    public static $_instance = null;
    public static $_legacyInstances = [];

    /**
     * @return self|null
     */
    public static function getInstance()
    {
        $moduleSettingService = ContainerFacade::get(ModuleSettingServiceInterface::class);
        $unzerPublicKey = $moduleSettingService->getString('UnzerPaymentPublicKey', Constants::MODULE_ID);
        $unzerPrivateKey = $moduleSettingService->getString('UnzerPaymentPrivateKey', Constants::MODULE_ID);

        if ($unzerPublicKey != '' && $unzerPrivateKey != '') {
            if (null === self::$_instance) {
                self::$_instance = new self(
                    (string)$unzerPrivateKey,
                    UnzerpaymentHelper::getInstance()->getUnzerLanguage()
                );
            }
            return self::$_instance;
        }
        return null;
    }

    /**
     * @param $paymenttype
     * @return mixed|self|null
     */
    public static function getLegacyInstance($paymenttype, $currency, $customertype)
    {
        $moduleSettingService = ContainerFacade::get(ModuleSettingServiceInterface::class);

        $paymentTypeCheck = $paymenttype . '-' . strtolower($customertype) . '-' . strtolower($currency);

        switch ($paymentTypeCheck) {
            case 'oscunzer_installment_paylater-b2c-chf':
                $configVar = 'production-UnzerPayLaterInstallmentB2CCHFPrivateKey';
            case 'oscunzer_installment_paylater-b2b-chf':
                $configVar = 'production-UnzerPayLaterInstallmentB2BCHFPrivateKey';
            case 'oscunzer_installment_paylater-b2c-eur':
                $configVar = 'production-UnzerPayLaterInstallmentB2CEURPrivateKey';
            case 'oscunzer_installment_paylater-b2b-eur':
                $configVar = 'production-UnzerPayLaterInstallmentB2BEURPrivateKey';
            case 'oscunzer_invoice-b2b-chf':
                $configVar = 'production-UnzerPayLaterInvoiceB2BCHFPrivateKey';
            case 'oscunzer_invoice-b2c-chf':
                $configVar = 'production-UnzerPayLaterInvoiceB2CCHFPrivateKey';
            case 'oscunzer_invoice-b2b-eur':
                $configVar = 'production-UnzerPayLaterInvoiceB2BEURPrivateKey';
            case 'oscunzer_invoice-b2c-eur':
                $configVar = 'production-UnzerPayLaterInvoiceB2CEURPrivateKey';
            default:
                $configVar = 'production-UnzerPrivateKey';
                break;
        }

        $unzerLegacyPrivateKey = $moduleSettingService->getString($configVar, 'osc-unzer');

        if ($unzerLegacyPrivateKey != '') {
            if (!isset(self::$_legacyInstances[$paymenttype])) {
                self::$_legacyInstances[$paymenttype] = new self(
                    (string)$unzerLegacyPrivateKey,
                    UnzerpaymentHelper::getInstance()->getUnzerLanguage()
                );
            }
            return self::$_legacyInstances[$paymenttype];
        }
        return null;
    }

    /**
     * @param $paymentId
     * @param $amount
     * @return bool
     */
    public function performChargeOnAuthorization( $paymentId, $amount = null ) {
        $charge = new Charge();
        if ( $amount ) {
            $charge->setAmount($amount);
        }
        $chargeResult = false;
        try {
            $chargeResult = $this->performChargeOnPayment($paymentId, $charge);
        } catch (\UnzerSDK\Exceptions\UnzerApiException $e) {
            $logger = $this->getServiceFromContainer(DebugHandler::class);
            $logger->addLog('performChargeOnPayment Error', 1, $e, [
                'paymentId' => $paymentId,
                'amount' => $amount
            ]);
        } catch (\RuntimeException $e) {
            $logger = $this->getServiceFromContainer(DebugHandler::class);
            $logger->addLog('performChargeOnPayment Error', 1, $e, [
                'paymentId' => $paymentId,
                'amount' => $amount
            ]);
        }
        return (bool)$chargeResult;
    }


    /**
     * @return array
     * @throws \UnzerSDK\Exceptions\UnzerApiException
     */
    public static function getAvailablePaymentMethods()
    {
        $unzerClient = self::getInstance();
        if (is_null($unzerClient)) {
            return [];
        }
        $keypairResponse = $unzerClient->fetchKeypair(true);
        $availablePaymentTypes = $keypairResponse->getAvailablePaymentTypes();
        usort($availablePaymentTypes, function ($a, $b) { return strcmp(strtolower($a->type), strtolower($b->type)); });
        foreach ($availablePaymentTypes as $availablePaymentTypeKey => &$availablePaymentType) {
            if ($availablePaymentType->type == 'PIS' || $availablePaymentType->type == 'giropay') {
                unset($availablePaymentTypes[$availablePaymentTypeKey]);
            }
        }
        return $availablePaymentTypes;
    }

    /**
     * @return array|false
     */
    public function getWebhooksList()
    {
        try {
            $webhooks = $this->fetchAllWebhooks();
            $logger = $this->getServiceFromContainer(DebugHandler::class);
            $logger->addLog('webhook Fetch', 2, false, [
                'webhooks' => $webhooks,
            ]);
            if (sizeof($webhooks) > 0) {
                return $webhooks;
            }
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @param $paymentType
     * @return array|string|string[]
     */
    public static function guessPaymentMethodClass($paymentType)
    {
        $newParts = [];
        $paymentType = str_replace('-', '_', $paymentType);
        $parts = explode('_', $paymentType);
        foreach ($parts as $part) {
            $newParts[] = ucfirst($part);
        }
        $className = join('', $newParts);
        if (class_exists("UnzerSDK\Resources\PaymentTypes\\" . $className)) {
            if ($className == 'OpenbankingPis') {
                return strtolower($className);
            }
            return lcfirst($className);
        }
        return $paymentType;
    }


}