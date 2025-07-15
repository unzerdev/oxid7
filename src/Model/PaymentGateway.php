<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Model;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Templating\TemplateRendererBridgeInterface;
use Unzer\UnzerPayment\Classes\UnzerpaymentClient;
use Unzer\UnzerPayment\Classes\UnzerpaymentHelper;
use Unzer\UnzerPayment\Constants\Constants;
use Unzer\UnzerPayment\Service\DebugHandler;
use Unzer\UnzerPayment\Traits\ServiceContainer;


class PaymentGateway extends PaymentGateway_parent
{
    use ServiceContainer;

    /**
     * @param $dAmount
     * @param $oOrder
     * @return true
     */
    public function executePayment($dAmount, &$oOrder)
    {
        if (!UnzerpaymentHelper::getInstance()->isUnzerPaymentMethod($oOrder->oxorder__oxpaymenttype->value)) {
            return parent::executePayment($dAmount, $oOrder);
        }
        $this->handleUnzerPayment($oOrder, $dAmount);
        $this->resetSession();
        return true;
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
     * @return void
     */
    public function resetSession()
    {
        $session = Registry::getSession();
        $session->deleteVariable('UnzerSelectedPaymentMethod');
    }


}