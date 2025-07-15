<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Controller;

use OxidEsales\Eshop\Core\Registry;
use Unzer\UnzerPayment\Classes\UnzerpaymentClient;
use Unzer\UnzerPayment\Classes\UnzerpaymentHelper;
use Unzer\UnzerPayment\Service\DebugHandler;
use Unzer\UnzerPayment\Traits\ServiceContainer;

/**
 * @inheritDoc
 *
 * @extends \OxidEsales\Eshop\Application\Controller\ThankYouController
 */
class ThankYouController extends ThankYouController_parent
{
    use ServiceContainer;

    /**
     * @return void
     * @throws \OxidEsales\Eshop\Core\Exception\DatabaseConnectionException
     * @throws \OxidEsales\Eshop\Core\Exception\DatabaseErrorException
     * @throws \UnzerSDK\Exceptions\UnzerApiException
     */
    public function init()
    {
        parent::init();

        $session = Registry::getSession();
        $logger = $this->getServiceFromContainer(DebugHandler::class);

        if ($session->getVariable('UnzerMetadataId')) {
            $order = $this->getOrder();
            if (UnzerpaymentHelper::getInstance()->isUnzerPaymentMethod($order->oxorder__oxpaymenttype->value)) {
                $UnzerMetadataId = $session->getVariable('UnzerMetadataId');

                $metadata = UnzerpaymentClient::getInstance()->fetchMetadata(
                    $UnzerMetadataId
                );
                $metadata->addMetadata(
                    'shopOrderReference', (string)$order->getFieldData('oxordernr')
                );

                $logger->addLog('Trying to set metadata', 3, false, [$metadata]);
                try {
                    UnzerpaymentClient::getInstance()->getResourceService()->updateResource(
                        $metadata
                    );
                } catch (\Exception $e) {
                    $logger->addLog('Could not update metadata', 2, $e, [$metadata]);
                }

                $UnzerPaypageId = $session->getVariable('UnzerPaypageId');
                $paypage = UnzerpaymentClient::getInstance()->fetchPaypageV2($UnzerPaypageId);
                $payment = $paypage->getPayments()[0];

                $paymentUnzerObject = UnzerpaymentClient::getInstance()->getUnzerObject()->fetchPayment($payment->getPaymentId());

                $logger->addLog('paymentUnzerObject', 3, false, [$paymentUnzerObject], );

                if ($payment->getTransactionStatus() == \UnzerSDK\Constants\TransactionStatus::STATUS_SUCCESS) {
                    if (sizeof($paymentUnzerObject->getCharges()) > 0) {
                        UnzerpaymentHelper::getInstance()->setOrderStatus(
                            $order->getId(),
                            'OK'
                        );
                        UnzerpaymentHelper::getInstance()->setOrderPaid(
                            $order->getId(),
                        );
                    } else {
                        UnzerpaymentHelper::getInstance()->setOrderStatus(
                            $order->getId(),
                            'AUTHORIZED'
                        );
                    }
                } else {
                    UnzerpaymentHelper::getInstance()->setOrderStatus(
                        $order->getId(),
                        'NOT_FINISHED'
                    );
                }

            }
            $session->deleteVariable('UnzerPaypageId');
            $session->deleteVariable('UnzerMetadataId');
        }
    }

    /**
     * @return mixed
     * @throws \UnzerSDK\Exceptions\UnzerApiException
     */
    public function render()
    {
        /** @var $oOrder \OxidEsales\Eshop\Application\Model\Order */
        $oOrder = $this->getOrder();

        if (UnzerpaymentHelper::getInstance()->isUnzerPaymentMethod($oOrder->oxorder__oxpaymenttype->value)) {
            $payment = UnzerpaymentClient::getInstance()->fetchPayment(
                $oOrder->oxorder__oxtransid->value
            );
            $paymentId = (\UnzerSDK\Services\IdService::getResourceTypeFromIdString($payment->getPaymentType()->getId()));
            if ($paymentId == 'ppy' || $paymentId == 'piv' || $paymentId == 'ivc') {
                $this->addTplParam('needsUnzerPaymentInfo', true);
                $this->addTplParam('unzerPaymentInfo', Registry::getSession()->getVariable('additionalUnzerPaymentInformation') ?? '');
            }
        }

        return parent::render();
    }

}