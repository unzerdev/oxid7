<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Service;

use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Core\Di\ContainerFacade;
use OxidEsales\EshopCommunity\Internal\Domain\Authentication\Bridge\PasswordServiceBridgeInterface;
use UnzerSDK\Resources\Payment;

final class ExpressCheckoutService
{
    public const ADDRESS_PART_PLACEHOLDER = ExpressCustomerDataNormalizer::ADDRESS_PART_PLACEHOLDER;
    public const SESSION_PAYPAL_PAYMENT_ID = 'paypal-express-checkout-payment-id';
    public const SESSION_PAYPAL_PAYMENT_TYPE_ID = 'paypal-express-checkout-payment-type-id';
    public const SESSION_GOOGLE_PAYMENT_TYPE_ID = 'google-express-checkout-payment-type-id';
    public const SESSION_APPLEPAY_PAYMENT_TYPE_ID = 'applepay-express-checkout-payment-type-id';
    public const SESSION_SELECTED_EXPRESS_METHOD = 'express-checkout-payment-id-selected';
    public const SESSION_PAYMENT_ID = 'unzer-express-paymentid';

    public const SESSION_CHECKOUT_USER_ID = 'unzer-express-checkout-user-id';
    public const SESSION_GUEST_CONTACT = 'unzer-express-guest-contact';
    public const SESSION_PAYPAL_RETURN_STATE = 'unzer-express-paypal-return-state';

    private const SESSION_USER_ID = 'usr';

    private ExpressCustomerDataNormalizer $normalizer;
    private ExpressUserService $userService;
    private ExpressCheckoutStateService $checkoutState;

    public function __construct(
        ?ExpressCustomerDataNormalizer $normalizer = null,
        ?ExpressUserService $userService = null,
        ?ExpressCheckoutStateService $checkoutState = null
    ) {
        $this->normalizer = $normalizer ?? new ExpressCustomerDataNormalizer();
        $this->userService = $userService ?? new ExpressUserService();
        $this->checkoutState = $checkoutState ?? new ExpressCheckoutStateService();
    }

    public function startCheckoutSessionFromUnzerPayment(Payment $payment, ?string $paymentMethodId): void
    {
        $this->startCheckoutDataWithNormalizedCustomerData(
            $this->normalizer->fromUnzerPayment($payment),
            $paymentMethodId
        );
    }

    public function startCheckoutSessionFromGooglePayData(array $paymentData, ?string $paymentMethodId): void
    {
        $this->startCheckoutDataWithNormalizedCustomerData(
            $this->normalizer->fromGooglePay($paymentData),
            $paymentMethodId
        );
    }

    public function startCheckoutSessionFromApplePayData(
        array $paymentData,
        array $shippingContact,
        array $billingContact,
        ?string $paymentMethodId
    ): void {
        $this->startCheckoutDataWithNormalizedCustomerData(
            $this->normalizer->fromApplePay($shippingContact, $billingContact),
            $paymentMethodId
        );
    }

    private function startCheckoutDataWithNormalizedCustomerData(?array $data, ?string $paymentMethodId): void
    {
        $email = $data['email'] ?? null;
        if (!is_string($email) || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('No valid express checkout contact email');
        }
        $email = trim($email);

        $session = Registry::getSession();
        $basket = $session->getBasket();
        if (!$basket || !$basket->getContents()) {
            throw new \RuntimeException('No basket available for express checkout');
        }

        /** @var User $user */
        $user = oxNew(User::class);
        $hasAccount = $user->loadActiveUser() && $user->getId() && $user->hasAccount();
        $passwordService = ContainerFacade::get(PasswordServiceBridgeInterface::class);
        if ($hasAccount) {
            $loginToken = (string) $session->getVariable('login-token');
            if (!$user->isLoadedFromCookie() && (
                $loginToken === '' || !$passwordService->verifyPassword(
                    (string) $user->getFieldData('oxpassword'),
                    $loginToken
                )
            )) {
                throw new \RuntimeException('Sign in again to start express checkout');
            }

            #if (strcasecmp(trim((string) $user->getFieldData('oxusername')), $email) !== 0) {
            #    throw new \RuntimeException('Express contact email must match your shop account');
            #}
        }

        $previousUser = $basket->getBasketUser();
        $previousAddress = $session->getVariable('deladrid');
        $previousShipping = $session->getVariable('sShipSet');
        $previousBasketShipping = $basket->getShippingId();
        $session->deleteVariable(self::SESSION_SELECTED_EXPRESS_METHOD);
        $session->deleteVariable(self::SESSION_CHECKOUT_USER_ID);
        $this->checkoutState->resetCheckoutPaymentState();

        try {
            if (!$hasAccount) {
                $user = $this->userService->createGuest($data);
                $guestLoginToken = $passwordService->hash('');
                $this->userService->setShippingAddress($user, $data);
            }
            $basket->setBasketUser($user);
            $basket->onUpdate();
            if ($paymentMethodId) {
                $this->checkoutState->setPaymentMethod($paymentMethodId, $user);
            }

            if (!$hasAccount) {
                $session->regenerateSessionId();
                $session->setVariable(self::SESSION_GUEST_CONTACT, [
                    'userId' => $user->getId(),
                    'email' => $email,
                ]);
                $session->setVariable('login-token', $guestLoginToken);
                $session->setVariable(self::SESSION_USER_ID, $user->getId());
            }
            $session->setVariable(self::SESSION_CHECKOUT_USER_ID, $user->getId());
            $session->setVariable(self::SESSION_SELECTED_EXPRESS_METHOD, $paymentMethodId);
        } catch (\Throwable $exception) {
            $basket->setBasketUser($previousUser);
            $basket->setShipping($previousBasketShipping);
            foreach (['deladrid' => $previousAddress, 'sShipSet' => $previousShipping] as $key => $value) {
                if ($value === null) {
                    $session->deleteVariable($key);
                } else {
                    $session->setVariable($key, $value);
                }
            }
            $this->checkoutState->resetCheckoutPaymentState();
            throw $exception;
        }
    }
}
