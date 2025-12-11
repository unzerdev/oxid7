<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Controller\Admin;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Core\DatabaseProvider;
use Unzer\UnzerPayment\Classes\UnzerpaymentClient;
use Unzer\UnzerPayment\Classes\UnzerpaymentHelper;
use Unzer\UnzerPayment\Constants\Constants;

class ModuleConfiguration extends ModuleConfiguration_parent
{
    /**
     * @return bool
     */
    public function isUnzerPaymentModuleConfig()
    {
        return $this->_sModuleId == Constants::MODULE_ID;
    }

    /**
     * @return mixed
     */
    public function render()
    {
        if (UnzerpaymentClient::getInstance()) {
            $this->addTplParam('unzer_webhooks', UnzerpaymentClient::getInstance()->getWebhooksList());
            foreach (UnzerpaymentClient::getAvailablePaymentMethods() as $paymentMethod) {
                if ($paymentMethod->type == 'clicktopay') {
                    $this->addTplParam('unzer_has_clicktopay', true);
                }
            }
        }
        return parent::render();
    }

    /**
     * @return void
     */
    public function createwebhook()
    {
        try {
            if (is_null(UnzerpaymentClient::getInstance())) {
                return;
            }
            UnzerpaymentClient::getInstance()->createWebhook(
               UnzerpaymentHelper::getInstance()->getNotifyUrl(),
                'all'
            );
            $this->addTplParam('unzer_success_message', 'Webhook successfully added');
        } catch (\Exception $e) {
            $this->addTplParam('unzer_config_error', 'Cannot add webhook, API Info: ' . $e->getMessage());
        }

    }

    /**
     * @return void
     */
    public function deletewebhook()
    {
        if ($webhookId = Registry::getRequest()->getRequestEscapedParameter('webhookId')) {
            try {
                UnzerpaymentClient::getInstance()->deleteWebhook(
                    $webhookId
                );
                $this->addTplParam('unzer_success_message', 'Webhook successfully deleted');
            } catch (\Exception $e) {
                $this->addTplParam('unzer_config_error', 'Cannot delete webhook, API Info: ' . $e->getMessage());
            }
        }
    }

    /**
     * @return void
     * @throws \OxidEsales\Eshop\Core\Exception\DatabaseConnectionException
     * @throws \OxidEsales\Eshop\Core\Exception\DatabaseErrorException
     * @throws \UnzerSDK\Exceptions\UnzerApiException
     */
    public function saveConfVars()
    {
        parent::saveConfVars();

        if (isset($_REQUEST['unzer_payment_set_method_status']) && $_REQUEST['unzer_payment_set_method_status'] == '1') {
            $available_payment_methods_strings = [];
            foreach (UnzerpaymentClient::getAvailablePaymentMethods() as $paymentMethod) {
                $available_payment_methods_strings[] = Constants::PAYMENT_METHOD_PREFIX . str_replace('-', '_', $paymentMethod->type);
            }

            // deactivate methods that are not available for the specific keypair
            $toDeactivateMethods = array_keys(Constants::PAYMENT_METHODS);
            foreach ($toDeactivateMethods as &$sMethod) {
                $sMethod = Constants::PAYMENT_METHOD_PREFIX . $sMethod;
            }
            foreach ($toDeactivateMethods as $sMethodKey => $sMethodName) {
                if (in_array($sMethodName, $available_payment_methods_strings)) {
                    unset($toDeactivateMethods[$sMethodKey]);
                }
            }
            $sPaymenthodIds = "'" . implode("','", $toDeactivateMethods) . "'";
            $sQ = "update oxpayments set oxactive = 0 where oxid in ($sPaymenthodIds)";
            DatabaseProvider::getDB()->Execute($sQ);

            $sPaymenthodIds = "'" . implode("','", $available_payment_methods_strings) . "'";
            $sQ = "update oxpayments set oxactive = 1 where oxid in ($sPaymenthodIds)";
            DatabaseProvider::getDB()->Execute($sQ);
        }

        $this->addTplParam('unzer_success_message', 'Settings successfully updated');

    }

}