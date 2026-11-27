<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Controller;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Core\Di\ContainerFacade;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;
use Unzer\UnzerPayment\Classes\UnzerpaymentClient;
use Unzer\UnzerPayment\Classes\UnzerpaymentHelper;
use Unzer\UnzerPayment\Constants\Constants;
use Unzer\UnzerPayment\Model\PaymentGateway;
use Unzer\UnzerPayment\PaymentMethods\UnzerApplepayPaymentMethod;
use Unzer\UnzerPayment\PaymentMethods\UnzerGooglepayPaymentMethod;
use Unzer\UnzerPayment\PaymentMethods\UnzerPaypalPaymentMethod;
use Unzer\UnzerPayment\Service\BasketService;
use Unzer\UnzerPayment\Service\DebugHandler;
use Unzer\UnzerPayment\Service\ExpressCheckoutService;
use Unzer\UnzerPayment\Service\PaymentService;
use Unzer\UnzerPayment\Traits\ServiceContainer;
use UnzerSDK\Resources\EmbeddedResources\GeoLocation;
use UnzerSDK\Resources\TransactionTypes\Authorization;
use UnzerSDK\Resources\TransactionTypes\Charge;

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

        $order = $this->getOrder();
        if ($order && (new \Unzer\UnzerPayment\Service\PreOrderService())->find('oxid', (string) $order->getId())) {
            foreach (['UnzerPaypageId', 'UnzerMetadataId', 'UnzerPreparedOrderId'] as $key) {
                $session->deleteVariable($key);
            }
            return;
        }

        if ($session->getVariable(ExpressCheckoutService::SESSION_SELECTED_EXPRESS_METHOD)) {
            if ($session->getVariable(ExpressCheckoutService::SESSION_PAYMENT_ID)) {
                $paymentId = $session->getVariable(ExpressCheckoutService::SESSION_PAYMENT_ID);
                $paymentUnzerObject = UnzerpaymentClient::getInstance()->fetchPayment(
                    $paymentId
                );
                $logger->addLog('paymentUnzerObject', 3, false, [$paymentUnzerObject], );
                $UnzerMetadataId = $paymentUnzerObject->getMetadata()->getId();
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

                if ($paymentUnzerObject->isCompleted()) {
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
                    $order->setUnzerOrderNr($order->getId());
                } else {
                    UnzerpaymentHelper::getInstance()->setOrderStatus(
                        $order->getId(),
                        'NOT_FINISHED'
                    );
                }
            }


        } elseif ($session->getVariable('UnzerMetadataId')) {
            if (UnzerpaymentHelper::getInstance()->isUnzerPaymentMethod($order->oxorder__oxpaymenttype->value)) {
                $UnzerMetadataId = $session->getVariable('UnzerMetadataId');
                $UnzerPreparedOrderId = $session->getVariable('UnzerPreparedOrderId');

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

                if ($UnzerPreparedOrderId != '') {
                    try {
                        $order->setUnzerOrderNr($UnzerPreparedOrderId);
                    } catch (\Exception $e) {
                        $logger->addLog('Could not update local unzer order id', 2, $e, [$UnzerPreparedOrderId]);
                    }
                }

                $unzerPaymentId = (\UnzerSDK\Services\IdService::getResourceTypeFromIdString($paymentUnzerObject->getPaymentType()->getId()));

                if ($unzerPaymentId === 'ppy') {
                    \Unzer\UnzerPayment\Service\PrepaymentStatus::updateExistingOrder($paymentUnzerObject, $order);
                } elseif ($unzerPaymentId == 'obp' && $payment->getTransactionStatus() == \UnzerSDK\Constants\TransactionStatus::STATUS_SUCCESS && isset($paymentUnzerObject->getCharges()[0])) {
                    if ($paymentUnzerObject->getCharges()[0]->isSuccess()) {
                        UnzerpaymentHelper::getInstance()->setOrderStatus(
                            $order->getId(),
                            'OK'
                        );
                        UnzerpaymentHelper::getInstance()->setOrderPaid(
                            $order->getId(),
                        );
                    } elseif ($paymentUnzerObject->getCharges()[0]->isPending()) {
                        UnzerpaymentHelper::getInstance()->setOrderStatus(
                            $order->getId(),
                            'NOT_FINISHED'
                        );
                    }
                } else {
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
            }
        }
        $session->deleteVariable('UnzerPaypageId');
        $session->deleteVariable('UnzerMetadataId');
        $session->deleteVariable('UnzerPreparedOrderId');
        self::resetExpressCheckoutSessionData();
    }

    /**
     * @return void
     */
    public static function resetExpressCheckoutSessionData() {
        $session = Registry::getSession();
        $session->deleteVariable(ExpressCheckoutService::SESSION_SELECTED_EXPRESS_METHOD);
        $session->deleteVariable(ExpressCheckoutService::SESSION_CHECKOUT_USER_ID);
        $session->deleteVariable(ExpressCheckoutService::SESSION_GOOGLE_PAYMENT_TYPE_ID);
        $session->deleteVariable(ExpressCheckoutService::SESSION_APPLEPAY_PAYMENT_TYPE_ID);
        $session->deleteVariable(ExpressCheckoutService::SESSION_PAYPAL_PAYMENT_ID);
        $session->deleteVariable(ExpressCheckoutService::SESSION_PAYPAL_RETURN_STATE);
        $session->deleteVariable(ExpressCheckoutService::SESSION_PAYPAL_PAYMENT_TYPE_ID);
        $session->deleteVariable(ExpressCheckoutService::SESSION_PAYMENT_ID);
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
            $moduleSettingService = ContainerFacade::get(ModuleSettingServiceInterface::class);
            if ($moduleSettingService->getString('UnzerPaymentMode', Constants::MODULE_ID) == '0') {
                $this->addTplParam('unzerPaymentId', $payment->getId());
                $this->addTplParam('unzerShortId', $payment->getInitialTransaction()->getShortId());
            }
        }

        return parent::render();
    }

}