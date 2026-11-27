<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\PaymentMethods;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Core\Di\ContainerFacade;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;
use Unzer\UnzerPayment\Classes\UnzerpaymentClient;
use Unzer\UnzerPayment\Constants\Constants;

class UnzerGooglepayPaymentMethod extends AbstractUnzerPaymentMethod
{
    const UNZER_SHORT_CODE = Constants::PAYMENT_METHODS['googlepay']['short_code'];
    const UNZER_LONG_CODE =  Constants::PAYMENT_METHODS['googlepay']['long_code'];
    const PAYMENT_METHOD_CODE =  Constants::PAYMENT_METHODS['googlepay']['payment_method_code'];
    const PAYMENT_METHOD_NAME = Constants::PAYMENT_METHODS['googlepay']['name'];

    public function hasExpressCheckout()
    {
        if (ContainerFacade::get(ModuleSettingServiceInterface::class)->getBoolean('UnzerPaymentGooglepayExpressCheckout', Constants::MODULE_ID)) {
            return true;
        }
        return false;
    }

    /**
     * @return void
     */
    public function fetchAndSaveChannelId(): void
    {
        try {
            $keyPair = UnzerpaymentClient::getInstance()->fetchKeypair(true);
            foreach ( $keyPair->getPaymentTypes() as $paymentType ) {
                if ( $paymentType->type === 'googlepay' ) {
                    $channelId = $paymentType->supports[0]->channel ?? null;
                    if ( $channelId ) {
                        Registry::getConfig()->saveShopConfVar(
                            'str',
                            'unzerGooglePayChannelId',
                            $channelId
                        );
                        return;
                    }
                }
            }
        } catch ( \Exception $e ) {
            // silent
        }
        Registry::getConfig()->saveShopConfVar(
            'str',
            'unzerGooglePayChannelId',
            ''
        );
    }
}
