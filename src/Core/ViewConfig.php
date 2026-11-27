<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Core;

use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\Field;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Core\Di\ContainerFacade;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;
use Unzer\UnzerPayment\Classes\UnzerpaymentClient;
use Unzer\UnzerPayment\Classes\UnzerpaymentHelper;
use Unzer\UnzerPayment\Constants\Constants;
use Unzer\UnzerPayment\PaymentMethods\UnzerGooglepayPaymentMethod;
use Unzer\UnzerPayment\Service\ExpressCheckoutService;
use Unzer\UnzerPayment\Service\ExpressPaymentMethods;
use Unzer\UnzerPayment\Service\ExpressUserService;

class ViewConfig extends ViewConfig_parent
{
    public function getUnzerSessionPaymentInfo(): string
    {
        /** @var string $addPaymentInfo */
        $addPaymentInfo = Registry::getSession()->getVariable('additionalUnzerPaymentInformation') ?? '';
        return $addPaymentInfo;
    }

    /**
     * @return bool
     */
    public function isUnzerExpressEnabled(): bool
    {
        return UnzerpaymentHelper::hasAtLeastOneActiveExpressCheckoutOption();
    }

    /**
     * @return bool
     */
    public function isExpressCheckout(): bool
    {
        return Registry::getSession()->getVariable(
                ExpressCheckoutService::SESSION_SELECTED_EXPRESS_METHOD
            ) !== null;
    }

    /**
     * @param string $paymentId
     * @return string|null
     */
    public function getUnzerExpressPaymentTitle(string $paymentId): ?string
    {
        $expressMethod = Registry::getSession()->getVariable(
            ExpressCheckoutService::SESSION_SELECTED_EXPRESS_METHOD
        );

        if ($expressMethod !== $paymentId) {
            return null;
        }

        return match ($paymentId) {
            ExpressPaymentMethods::PAYPAL => 'PayPal Express',
            ExpressPaymentMethods::GOOGLE_PAY => 'Google Pay Express',
            ExpressPaymentMethods::APPLE_PAY => 'Apple Pay Express',
            default => null,
        };
    }

    public function getUnzerCheckoutAddressUser(User $user): User
    {
        if (!ExpressUserService::isExpressGuest($user)) {
            return $user;
        }

        $session = Registry::getSession();
        $contact = $session->getVariable(ExpressCheckoutService::SESSION_GUEST_CONTACT);
        $email = $contact['email'] ?? null;
        $displayUser = clone $user;
        // The address widget reads oxusername. Supply a display-only field.
        $displayUser->oxuser__oxusername = new Field(
            $user->getId() === $session->getVariable('usr')
                && $user->getId() === ($contact['userId'] ?? null)
                && is_string($email)
                && filter_var($email, FILTER_VALIDATE_EMAIL)
                ? $email
                : '',
            Field::T_RAW
        );

        return $displayUser;
    }

    public function getUnzerJSParams(): string
    {
        (new UnzerGooglepayPaymentMethod())->fetchAndSaveChannelId();
        $moduleSettingService = ContainerFacade::get(ModuleSettingServiceInterface::class);
        $unzerPublicKey = $moduleSettingService->getString('UnzerPaymentPublicKey', Constants::MODULE_ID);

        $lang = Registry::getLang();
        $iLang = $lang->getBaseLanguage();
        $sLang = $lang->getLanguageAbbr($iLang);

        $params = [
            'generic_error_message_express' => \OxidEsales\Eshop\Core\Registry::getLang()->translateString('UNZER_GENERIC_ERROR'),
            'publicKey' => $unzerPublicKey,
            'stoken' => Registry::getSession()->getSessionChallengeToken(),
            'locale' => $sLang,
            'currency' => Registry::getSession()->getBasket()->getBasketCurrency()->name,
            'express' => [
                'paypal' => UnzerpaymentHelper::hasActiveExpressCheckoutOption('Paypal'),
                'googlepay' => UnzerpaymentHelper::hasActiveExpressCheckoutOption('Googlepay'),
                'applepay' => UnzerpaymentHelper::hasActiveExpressCheckoutOption('Applepay'),
                'is_express' => !((empty($_GET['isExpressCheckout']) || $_GET['isExpressCheckout'] !== 'true')),
                'current_express_method' => Registry::getSession()->getVariable(ExpressCheckoutService::SESSION_SELECTED_EXPRESS_METHOD),
            ],
            'google_pay_options' => [
                'gatewayMerchantId' => Registry::getConfig()->getShopConfVar('unzerGooglePayChannelId'),
                'merchantInfo' => [
                    'merchantId' => $moduleSettingService->getString('UnzerPaymentGooglepayMerchantId', Constants::MODULE_ID),
                    'merchantName' => $moduleSettingService->getString('UnzerPaymentGooglepayMerchantName', Constants::MODULE_ID),
                ],
                'countryCode' => 'DK',
                'buttonOptions' => [
                    'buttonColor' => 'default',
                    'buttonSizeMode' => 'fill'
                ],
                'allowedCardNetworks' => ["MASTERCARD","VISA"],
                'allowCreditCards' => true,
                'allowPrepaidCards' => true,
            ],
            'applepay_options' => [
                'countryCode' => 'DE',
                'supportedNetworks' => ['masterCard', 'visa'],
                'merchantCapabilities' => ['supports3DS'],
                'shopName' => Registry::getConfig()->getActiveShop()->getFieldData('oxname'),
            ],
            'urls' => [
                'paypal' => UnzerpaymentHelper::getInstance()->getExpressCoUrl('paypalExpress'),
                'googlePay' => UnzerpaymentHelper::getInstance()->getExpressCoUrl('googleExpress'),
                'applePay' => UnzerpaymentHelper::getInstance()->getExpressCoUrl('applepayExpress')
            ],
        ];

        return json_encode(
            $params,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG
            | JSON_HEX_APOS
            | JSON_HEX_AMP
            | JSON_HEX_QUOT
        );
    }
}
