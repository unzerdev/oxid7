<?php

namespace Unzer\UnzerPayment\Controller;

use Exception;
use OxidEsales\Eshop\Application\Controller\FrontendController;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\ShopVersion;
use OxidEsales\EshopCommunity\Core\Di\ContainerFacade;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;
use OxidEsales\Facts\Facts;
use Unzer\UnzerPayment\Service\MetadataService;
use Unzer\UnzerPayment\Classes\UnzerpaymentClient;
use Unzer\UnzerPayment\Classes\UnzerpaymentHelper;
use Unzer\UnzerPayment\Constants\Constants;
use Unzer\UnzerPayment\PaymentMethods\AbstractUnzerPaymentMethod;
use Unzer\UnzerPayment\PaymentMethods\UnzerApplepayPaymentMethod;
use Unzer\UnzerPayment\PaymentMethods\UnzerGooglepayPaymentMethod;
use Unzer\UnzerPayment\PaymentMethods\UnzerPaypalPaymentMethod;
use Unzer\UnzerPayment\Service\ExpressCheckoutService;
use Unzer\UnzerPayment\Service\ExpressPaymentMethods;
use UnzerSDK\Resources\Basket as UnzerBasket;
use UnzerSDK\Resources\EmbeddedResources\BasketItem;
use UnzerSDK\Resources\TransactionTypes\Authorization;
use UnzerSDK\Resources\TransactionTypes\Charge;

class ExpressController extends FrontendController
{
    public const CONTROLLER_KEY = 'unzer_express';

    public const PAYPAL_EXPRESS_ACTION = 'paypalExpress';
    public const PAYPAL_EXPRESS_RETURN_ACTION = 'paypalExpressReturn';
    public const GOOGLE_PAY_EXPRESS_ACTION = 'googleExpress';
    public const APPLE_PAY_EXPRESS_ACTION = 'applepayExpress';

    /**
     * Starts PayPal Express at Unzer and returns the redirect URL.
     */
    public function paypalExpress(): void
    {
        $this->requireExpressPost();

        try {
            ThankYouController::resetExpressCheckoutSessionData();
            $paymentTypeId = trim(
                (string) $this->getRequestValue(
                    'paymentTypeId',
                    ''
                )
            );

            if ($paymentTypeId === '') {
                $this->sendJsonError(
                    'paymentTypeId is missing',
                    400
                );
            }

            $basket = Registry::getSession()->getBasket();

            if (!$basket) {
                throw new \RuntimeException(
                    'OXID basket is not available'
                );
            }

            $basket->calculateBasket(true);

            $basketPrice = $basket->getPrice();

            if (!$basketPrice) {
                throw new \RuntimeException(
                    'Could not determine basket price'
                );
            }

            $cartTotal = (float) $basketPrice->getBruttoPrice();

            $currencyObject = Registry::getConfig()
                ->getActShopCurrencyObject();

            if (!$currencyObject || empty($currencyObject->name)) {
                throw new \RuntimeException(
                    'Could not determine shop currency'
                );
            }

            $currency = (string) $currencyObject->name;

            $unzerBasket = (new UnzerBasket())
                ->setTotalValueGross($cartTotal)
                ->setCurrencyCode($currency);

            $basketItem = (new BasketItem())
                ->setAmountPerUnitGross($cartTotal)
                ->setQuantity(1)
                ->setTitle('-');

            $unzerBasket->addBasketItem(
                $basketItem
            );

            $unzerBasketResult = UnzerpaymentClient::getInstance()
                ->createBasket($unzerBasket);

            $metadata = (new MetadataService())->getMetadata(1);

            $returnState = bin2hex(random_bytes(32));
            $returnUrl = $this->getControllerUrl(
                self::PAYPAL_EXPRESS_RETURN_ACTION
            ) . '&expressState=' . $returnState;

            $moduleSettingService = ContainerFacade::get(ModuleSettingServiceInterface::class);
            $bookingMode = (new UnzerpaymentHelper())->getPaymentMethodChargeMode(UnzerPaypalPaymentMethod::class);
            if ($bookingMode == '') {
                $bookingMode = $moduleSettingService->getString('UnzerPaymentMode', Constants::MODULE_ID) == '0' ?
                    'authorize' : 'charge';
            }

            if (
                $bookingMode
                === 'authorize'
            ) {
                $transaction = (new Authorization(
                    $cartTotal,
                    $currency,
                    $returnUrl
                ))->setCheckoutType(
                    'express',
                    $paymentTypeId
                );

                $resultTransaction = UnzerpaymentClient::getInstance()
                    ->performAuthorization(
                        $transaction,
                        $paymentTypeId,
                        null,
                        $metadata,
                        $unzerBasketResult
                    );
            } else {
                $transaction = (new Charge(
                    $cartTotal,
                    $currency,
                    $returnUrl
                ))->setCheckoutType(
                    'express',
                    $paymentTypeId
                );

                $resultTransaction = UnzerpaymentClient::getInstance()
                    ->performCharge(
                        $transaction,
                        $paymentTypeId,
                        null,
                        $metadata,
                        $unzerBasketResult
                    );
            }

            $session = Registry::getSession();

            $session->setVariable(
                ExpressCheckoutService::SESSION_PAYPAL_PAYMENT_ID,
                $resultTransaction->getPaymentId()
            );
            $session->setVariable(ExpressCheckoutService::SESSION_PAYPAL_RETURN_STATE, $returnState);

            $session->setVariable(
                ExpressCheckoutService::SESSION_PAYPAL_PAYMENT_TYPE_ID,
                $paymentTypeId
            );

            $this->sendJson([
                'paymentId' => $resultTransaction->getPaymentId(),
                'redirectUrl' => $resultTransaction->getRedirectUrl(),
            ]);
        } catch (\Throwable $exception) {
            Registry::getLogger()->error('Unzer Express checkout error', ['exception' => $exception]);
            $this->sendJsonError('Payment error', 500);
        }
    }

    /**
     * Return endpoint after PayPal authentication/approval at Unzer.
     */
    public function paypalExpressReturn(): void
    {
        $session = Registry::getSession();

        $state = $this->getRequestValue('expressState');
        $expectedState = $session->getVariable(ExpressCheckoutService::SESSION_PAYPAL_RETURN_STATE);
        if (!is_string($state) || !is_string($expectedState) || !hash_equals($expectedState, $state)) {
            $this->redirectToBasket();
        }
        $session->deleteVariable(ExpressCheckoutService::SESSION_PAYPAL_RETURN_STATE);

        $paymentId = $session->getVariable(
            ExpressCheckoutService::SESSION_PAYPAL_PAYMENT_ID
        );

        if (empty($paymentId)) {
            $this->redirectToBasket();
        }

        try {
            $payment = UnzerpaymentClient::getInstance()
                ->fetchPayment($paymentId);

            (new ExpressCheckoutService())
                ->startCheckoutSessionFromUnzerPayment(
                    $payment,
                    ExpressPaymentMethods::PAYPAL,
                );
        } catch (\Throwable $exception) {
            $this->addPaymentError(
                $exception
            );

            $this->redirectToBasket();
        }

        $session->setVariable(
            ExpressCheckoutService::SESSION_SELECTED_EXPRESS_METHOD,
            ExpressPaymentMethods::PAYPAL
        );

        $this->redirect(
            $this->getCheckoutUrl()
        );
    }

    /**
     * Initializes Google Pay Express checkout from the wallet data.
     */
    public function googleExpress(): void
    {
        $this->requireExpressPost();

        try {
            ThankYouController::resetExpressCheckoutSessionData();

            $paymentTypeId = trim(
                (string) $this->getRequestValue(
                    'paymentTypeId',
                    ''
                )
            );

            if ($paymentTypeId === '') {
                $this->sendJsonError(
                    'paymentTypeId is missing',
                    400
                );
            }

            $paymentData = $this->getArrayRequestValue(
                'paymentData'
            );

            if ($paymentData === []) {
                $this->sendJsonError(
                    'paymentData is missing',
                    400
                );
            }

            (new ExpressCheckoutService())
                ->startCheckoutSessionFromGooglePayData(
                    $paymentData,
                    ExpressPaymentMethods::GOOGLE_PAY
                );

            $session = Registry::getSession();

            $session->setVariable(
                ExpressCheckoutService::SESSION_GOOGLE_PAYMENT_TYPE_ID,
                $paymentTypeId
            );

            $session->setVariable(
                ExpressCheckoutService::SESSION_SELECTED_EXPRESS_METHOD,
                ExpressPaymentMethods::GOOGLE_PAY
            );

            $this->sendJson([
                'redirectUrl' => $this->getCheckoutUrl(),
            ]);
        } catch (\Throwable $exception) {
            Registry::getLogger()->error('Unzer Express checkout error', ['exception' => $exception]);
            $this->sendJsonError('Payment error', 500);
        }
    }

    /**
     * Initializes Apple Pay Express checkout from the wallet contacts.
     */
    public function applepayExpress(): void
    {
        $this->requireExpressPost();

        try {
            ThankYouController::resetExpressCheckoutSessionData();

            $paymentTypeId = trim(
                (string) $this->getRequestValue(
                    'paymentTypeId',
                    ''
                )
            );

            if ($paymentTypeId === '') {
                $this->sendJsonError(
                    'paymentTypeId is missing',
                    400
                );
            }

            $paymentData = $this->getArrayRequestValue(
                'paymentData'
            );

            $shippingContact = $this->getArrayRequestValue(
                'shippingContact'
            );

            $billingContact = $this->getArrayRequestValue(
                'billingContact'
            );

            if ($shippingContact === []) {
                $this->sendJsonError(
                    'shippingContact is missing',
                    400
                );
            }

            (new ExpressCheckoutService())
                ->startCheckoutSessionFromApplePayData(
                    $paymentData,
                    $shippingContact,
                    $billingContact,
                    ExpressPaymentMethods::APPLE_PAY
                );

            $session = Registry::getSession();

            $session->setVariable(
                ExpressCheckoutService::SESSION_APPLEPAY_PAYMENT_TYPE_ID,
                $paymentTypeId
            );

            $session->setVariable(
                ExpressCheckoutService::SESSION_SELECTED_EXPRESS_METHOD,
                ExpressPaymentMethods::APPLE_PAY
            );

            $this->sendJson([
                'redirectUrl' => $this->getCheckoutUrl(),
            ]);
        } catch (\Throwable $exception) {
            Registry::getLogger()->error('Unzer Express checkout error', ['exception' => $exception]);
            $this->sendJsonError('Payment error', 500);
        }
    }

    /**
     * Returns a form/query or JSON request value.
     */
    private function getRequestValue(
        string $key,
        mixed $default = null
    ): mixed {
        $requestValue = Registry::getRequest()
            ->getRequestParameter($key);

        if ($requestValue !== null) {
            return $requestValue;
        }

        $payload = $this->getJsonRequestBody();

        if (
            is_array($payload)
            && array_key_exists($key, $payload)
        ) {
            return $payload[$key];
        }

        return $default;
    }

    private function getArrayRequestValue(
        string $key
    ): array {
        $value = $this->getRequestValue(
            $key,
            []
        );

        if (is_string($value)) {
            $decodedValue = json_decode(
                $value,
                true
            );

            if (is_array($decodedValue)) {
                return $decodedValue;
            }

            return [];
        }

        return is_array($value)
            ? $value
            : [];
    }

    private function requireExpressPost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->sendJsonError('POST required', 405);
        }

        $token = $this->getJsonRequestBody()['stoken'] ?? null;
        $expected = Registry::getSession()->getSessionChallengeToken();
        if (!is_string($token) || !is_string($expected) || $expected === '' || !hash_equals($expected, $token)) {
            $this->sendJsonError('Invalid checkout session', 403);
        }
    }

    private function getJsonRequestBody(): array
    {
        static $payload;

        if ($payload !== null) {
            return $payload;
        }

        $rawBody = file_get_contents(
            'php://input'
        );

        if (!$rawBody) {
            return $payload = [];
        }

        $decoded = json_decode(
            $rawBody,
            true
        );

        return $payload = is_array($decoded)
            ? $decoded
            : [];
    }

    private function getControllerUrl(
        string $action
    ): string {
        $baseUrl = $this->getShopSecureHomeUrl();

        return $baseUrl
            . 'cl='
            . rawurlencode('unzer_payment_express')
            . '&fnc='
            . rawurlencode($action);
    }

    private function getShopSecureHomeUrl(): string
    {
        return html_entity_decode(
            Registry::getConfig()->getShopSecureHomeUrl(),
            ENT_QUOTES | ENT_HTML5
        );
    }

    private function getCheckoutUrl(): string
    {
        return $this->getShopSecureHomeUrl()
            . 'cl=order'
            . '&isExpressCheckout=true';
    }

    private function getBasketUrl(): string
    {
        return $this->getShopSecureHomeUrl()
            . 'cl=basket';
    }

    private function redirectToBasket(): never
    {
        $this->redirect(
            $this->getBasketUrl()
        );
    }

    private function redirect(
        string $url,
        int $statusCode = 302
    ): never {
        header(
            'Location: ' . $url,
            true,
            $statusCode
        );

        exit;
    }

    private function sendJson(
        array $data,
        int $statusCode = 200
    ): never {
        http_response_code(
            $statusCode
        );

        header(
            'Content-Type: application/json; charset=UTF-8'
        );

        echo json_encode(
            $data,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_THROW_ON_ERROR
        );

        exit;
    }

    private function sendJsonError(
        string $message,
        int $statusCode = 500
    ): never {
        $this->sendJson(
            [
                'success' => false,
                'message' => $message,
            ],
            $statusCode
        );
    }

    private function addPaymentError(
        \Throwable $exception
    ): void {

        Registry::getLogger()->error('Unzer Express checkout error', ['exception' => $exception]);

        Registry::getUtilsView()
            ->addErrorToDisplay('UNZER_PAYMENT_ERROR');

    }
}