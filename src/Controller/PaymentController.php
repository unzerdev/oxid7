<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Controller;

use OxidEsales\Eshop\Application\Model\Country;
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

            /* @var $oUser \OxidEsales\Eshop\Application\Model\User */
            $oUser = $this->getUser();

            $country = oxNew(Country::class);
            $country->load($oUser->oxuser__oxcountryid->value);
            $billingCountryIso = $country->oxcountry__oxisoalpha2->value;

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

                $countryOK = true;
                if (sizeof($paymentModel::ALLOWED_COUNTRIES)) {
                    if (!in_array($billingCountryIso, $paymentModel::ALLOWED_COUNTRIES)) {
                        $countryOK = false;
                    }
                }

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
                if ($currencyOK && $countryOK) {
                    $paymentList[$key] = $payment;
                }
            }
        }


        return $paymentList;
    }
}