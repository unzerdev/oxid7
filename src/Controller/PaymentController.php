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
 * @extends \OxidEsales\Eshop\Application\Controller\PaymentController
 */
class PaymentController extends PaymentController_parent
{
    use ServiceContainer;

    /**
     * Template variable getter. Returns paymentlist
     *
     * @return array<array-key, mixed>|object
     *
     */
    public function getPaymentList()
    {
        $paymentList = (array)parent::getPaymentList();
        if (UnzerpaymentClient::getInstance()) {
            $unzerPaymentMethods = UnzerpaymentClient::getInstance()->getAvailablePaymentMethods();

            $actShopCurrency = Registry::getConfig()->getActShopCurrencyObject();

            $paymentListRaw = $paymentList;
            $paymentList = [];

            /**
             * @var \Unzer\UnzerPayment\Model\Payment $payment
             */
            foreach ($paymentListRaw as $key => $payment) {
                if (!is_object($payment)) {
                    continue;
                }
                // any non-unzer payment ...
                if (!$payment->isUnzerPaymentMethod()) {
                    $paymentList[$key] = $payment;
                    continue;
                }

                $currencyOK = false;
                $paymentModel = $payment->getUnzerPaymentMethodModel();
                foreach ($unzerPaymentMethods as $unzerPaymentMethod) {
                    if (str_replace('-', '_', strtolower($unzerPaymentMethod->type)) == $paymentModel::UNZER_LONG_CODE) {
                        if (isset($unzerPaymentMethod->supports[0]->currency)) {
                            foreach ($unzerPaymentMethod->supports[0]->currency as $currency_code) {
                                if ($currency_code == $actShopCurrency->name) {
                                    $currencyOK = true;
                                }
                            }
                        }
                    }
                }
                if ($currencyOK) {
                    $paymentList[$key] = $payment;
                }
            }
        }


        return $paymentList;
    }
}