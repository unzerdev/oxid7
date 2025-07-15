<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Controller\Admin;

use OxidEsales\Eshop\Application\Controller\Admin\AdminDetailsController;
use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Templating\TemplateRendererBridgeInterface;
use Unzer\UnzerPayment\Classes\UnzerpaymentClient;
use Unzer\UnzerPayment\Classes\UnzerpaymentHelper;
use Unzer\UnzerPayment\Constants\Constants;
use UnzerSDK\Constants\TransactionTypes;
use UnzerSDK\Resources\TransactionTypes\Cancellation;

class AdminOrderController extends AdminDetailsController
{

    /**
     * @return string
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function render()
    {
        parent::render();

        $sOxId = $this->getEditObjectId();
        if (!empty($sOxId)) {
            $this->addTplParam('sOxid', $sOxId);
            $oOrder = $this->getEditObject();
            $this->addTplParam('oOrder', $oOrder);
            $this->addTplParam('isUnzerPaymentOrder', false);
            $this->addTplParam('isLegacyUnzerPaymentOrder', false);
            if (UnzerpaymentHelper::getInstance()->isUnzerPaymentMethod($oOrder->oxorder__oxpaymenttype->value) ||
                UnzerpaymentHelper::getInstance()->isUnzerLegacyPaymentMethod($oOrder->oxorder__oxpaymenttype->value) ) {
                if (!empty($oOrder->oxorder__oxtransid->value)) {
                    try {
                        if (UnzerpaymentHelper::getInstance()->isUnzerPaymentMethod($oOrder->oxorder__oxpaymenttype->value)) {
                            $transactions = UnzerpaymentHelper::getInstance()->getTransactions(
                                $oOrder->oxorder__oxtransid->value,
                                $oOrder
                            );
                        } else {
                            $transactions = UnzerpaymentHelper::getInstance()->getLegacyTransactions(
                                $oOrder->oxorder__oxpaymenttype->value,
                                $oOrder->oxorder__oxtransid->value,
                                $oOrder
                            );;
                            $this->addTplParam('isLegacyUnzerPaymentOrder', true);
                        }
                        $payment = UnzerpaymentClient::getInstance()->fetchPayment(
                            $oOrder->oxorder__oxtransid->value
                        );
                        $paymentId = (\UnzerSDK\Services\IdService::getResourceTypeFromIdString($payment->getPaymentType()->getId()));
                        if ($paymentId == 'ppy' || $paymentId == 'piv' || $paymentId == 'ivc') {
                            $container = ContainerFactory::getInstance()->getContainer();
                            $renderer = $container->get(TemplateRendererBridgeInterface::class)->getTemplateRenderer();
                            $params = [];
                            $params['unzer_amount'] = $payment->getInitialTransaction()->getAmount();
                            $params['unzer_currency'] = $payment->getInitialTransaction()->getCurrency();
                            $params['unzer_account_holder'] = $payment->getInitialTransaction()->getHolder();
                            $params['unzer_account_iban'] = $payment->getInitialTransaction()->getIban();
                            $params['unzer_account_bic'] = $payment->getInitialTransaction()->getBic();
                            $params['unzer_account_descriptor'] = $payment->getInitialTransaction()->getDescriptor();
                            $this->addTplParam('additionalUnzerPaymentInformation', $renderer->renderTemplate('@' . Constants::MODULE_ID . '/_inc/transfer_data', $params));
                        }
                        $this->addTplParam('unzer_transactions', $transactions);
                        if (sizeof($transactions['transactions']) == 1) {
                            if ($transactions['transactions'][0]['status'] == 'error') {
                                $this->addTplParam('unzerNoActionsBecauseError', true);
                                UnzerpaymentHelper::getInstance()->setOrderStatus(
                                    $sOxId,
                                    'CANCELED',
                                );
                            }
                        }
                        $this->addTplParam('isUnzerPaymentOrder', true);
                    } catch (\Exception $e) {
                        $this->addTplParam('unzer_error_message', 'Error, API Info: ' . $e->getMessage());
                    }
                }
            }
        }

        return "@unzer_payment/backend/admin_order_cntrl.html.twig";

    }

    /**
     * @return void
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function executeCapture()
    {
        if ($unzer_capture_amount = Registry::getRequest()->getRequestEscapedParameter('unzer_capture_amount')) {
            $unzer_capture_amount = (float)str_replace(',', '.', $unzer_capture_amount);
            if ($unzer_capture_amount > 0) {
                $oOrder = $this->getEditObject();
                try {
                    if (UnzerpaymentHelper::getInstance()->isUnzerLegacyPaymentMethod($oOrder->oxorder__oxpaymenttype->value)) {
                        $unzerClient = UnzerpaymentHelper::getInstance()->buildLegacyClient(
                            $oOrder->oxorder__oxpaymenttype->value,
                            $oOrder
                        );
                        $authorizationId = UnzerpaymentHelper::getInstance()->getLegacyPaymentId($oOrder, $oOrder->oxorder__oxtransid->value);
                    } else {
                        $unzerClient = UnzerpaymentClient::getInstance();
                        $authorizationId = $oOrder->oxorder__oxtransid->value;
                    }
                    try {
                        $successCharge = $unzerClient->performChargeOnAuthorization(
                            $authorizationId,
                            $unzer_capture_amount
                        );
                        if ($successCharge) {
                            UnzerpaymentHelper::getInstance()->setOrderPaid(
                                $oOrder->getId()
                            );
                            $this->addTplParam('unzer_success_message', 'Amount successfully captured');
                        } else {
                            $this->addTplParam('unzer_error_message', 'Capture not successful');
                        }
                    } catch (\Exception $e) {
                        $this->addTplParam('unzer_error_message', 'Capture not successful, Info: ' . $e->getMessage());
                    }
                } catch (\Exception $e) {
                    $this->addTplParam('unzer_error_message', 'Capture not successful, API Info: ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * @return void
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function executeCancel()
    {
        if ($cancelUnzerPayment = Registry::getRequest()->getRequestEscapedParameter('unzer_cancel_payment')) {
            if ($cancelUnzerPayment == 'unzer_cancel_payment_action') {
                $unzer_cancel_amount = Registry::getRequest()->getRequestEscapedParameter('unzer_cancel_amount');
                $unzer_cancel_amount = (float)str_replace(',', '.', $unzer_cancel_amount);
                $oOrder = $this->getEditObject();
                try {
                    if (UnzerpaymentHelper::getInstance()->isUnzerLegacyPaymentMethod($oOrder->oxorder__oxpaymenttype->value)) {
                        $unzerClient = UnzerpaymentHelper::getInstance()->buildLegacyClient(
                            $oOrder->oxorder__oxpaymenttype->value,
                            $oOrder
                        );
                        $paymentId = UnzerpaymentHelper::getInstance()->getLegacyPaymentId($oOrder, $oOrder->oxorder__oxtransid->value);
                    } else {
                        $unzerClient = UnzerpaymentClient::getInstance();
                        $paymentId = $oOrder->oxorder__oxtransid->value;
                    }
                    $paymentObject = $unzerClient->fetchPayment($paymentId);
                    $paymentTypeShort = (\UnzerSDK\Services\IdService::getResourceTypeFromIdString($paymentObject->getPaymentType()->getId()));
                    try {
                        if ($paymentTypeShort == 'pdd' || $paymentTypeShort == 'piv' || $paymentTypeShort == 'pit') {
                            $cancellation = new Cancellation();
                            $cancellation->setAmount($unzer_cancel_amount);
                            if ($paymentObject->getAmount()->getCharged() > 0) {
                                $unzerClient->cancelChargedPayment(
                                    $paymentId,
                                    $cancellation
                                );
                            } else {
                                $unzerClient->cancelAuthorizedPayment(
                                    $paymentId,
                                    $cancellation
                                );
                            }
                        } else {
                            $unzerClient->cancelPayment(
                                $paymentId,
                                $unzer_cancel_amount
                            );
                        }
                        $this->addTplParam('unzer_success_message', 'Amount successfully canceled');
                    } catch (\Exception $e) {
                        $this->addTplParam('unzer_error_message', 'Cancel not successful, Info: ' . $e->getMessage());
                    }
                } catch (\Exception $e) {
                    $this->addTplParam('unzer_error_message', 'Cancel not successful, API Info: ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * @return void
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function executeRefund()
    {
        if ($unzer_refund_amount = Registry::getRequest()->getRequestEscapedParameter('unzer_refund_amount')) {
            $unzer_refund_amount = (float)str_replace(',', '.', $unzer_refund_amount);
            if ($unzer_refund_amount > 0) {
                $oOrder = $this->getEditObject();
                try {
                    if (UnzerpaymentHelper::getInstance()->isUnzerLegacyPaymentMethod($oOrder->oxorder__oxpaymenttype->value)) {
                        $unzerClient = UnzerpaymentHelper::getInstance()->buildLegacyClient(
                            $oOrder->oxorder__oxpaymenttype->value,
                            $oOrder
                        );
                        $paymentId = UnzerpaymentHelper::getInstance()->getLegacyPaymentId($oOrder, $oOrder->oxorder__oxtransid->value);
                    } else {
                        $unzerClient = UnzerpaymentClient::getInstance();
                        $paymentId = $oOrder->oxorder__oxtransid->value;
                    }
                    try {
                        $cancellation = new Cancellation($unzer_refund_amount);
                        $createdCancellation = $unzerClient->cancelChargedPayment(
                            $paymentId,
                            $cancellation
                        );
                        $cancellations = [$createdCancellation];
                    } catch (\Exception $e) {
                        try {
                            $cancellation = new Cancellation($unzer_refund_amount);
                            $createdCancellation = $unzerClient->cancelAuthorizedPayment(
                                $paymentId,
                                $cancellation
                            );
                            $cancellations = [$createdCancellation];
                        } catch (\Exception $e) {
                            $cancellations = $unzerClient->cancelPayment(
                                $paymentId,
                                $unzer_refund_amount
                            );
                        }
                    }

                    if (isset($cancellations) && sizeof($cancellations)) {
                        $this->addTplParam('unzer_success_message', 'Amount successfully refunded');
                    } else {
                        $this->addTplParam('unzer_error_message', 'Refund not successful, Info: ' . $e->getMessage());
                    }
                } catch (\Exception $e) {
                    $this->addTplParam('unzer_error_message', 'Refund not successful, API Info: ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * @return Order
     */
    public function getEditObject()
    {
        $soxId = $this->getEditObjectId();
        if (empty($this->_oEditObject) && isset($soxId) && $soxId != '-1') {
            $this->_oEditObject = oxNew(Order::class);
            $this->_oEditObject->load($soxId);
        }
        return $this->_oEditObject;
    }

}