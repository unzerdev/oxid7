<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Service;

use OxidEsales\Eshop\Core\Registry;

final class RedirectSessionService
{
    public const SESSION_REQUIRED = 'unzer_payment_redirect_required';
    public const SESSION_URL = 'unzer_payment_redirect_url';
    public const SESSION_ORDER_ID = 'unzer_payment_redirect_order_id';
    public const SESSION_PAYMENT_ID = 'unzer_payment_redirect_payment_id';

    public function clear(): void
    {
        $session = Registry::getSession();
        foreach ([self::SESSION_REQUIRED, self::SESSION_URL, self::SESSION_ORDER_ID, self::SESSION_PAYMENT_ID] as $key) {
            $session->deleteVariable($key);
        }
    }

    public function store(string $redirectUrl, string $orderId, string $paymentId): void
    {
        $session = Registry::getSession();
        $session->setVariable(self::SESSION_REQUIRED, true);
        $session->setVariable(self::SESSION_URL, $redirectUrl);
        $session->setVariable(self::SESSION_ORDER_ID, $orderId);
        $session->setVariable(self::SESSION_PAYMENT_ID, $paymentId);
    }

    public function isRequired(): bool
    {
        return (bool) Registry::getSession()->getVariable(self::SESSION_REQUIRED);
    }

    public function getUrl(): ?string
    {
        $value = Registry::getSession()->getVariable(self::SESSION_URL);
        return $value ? (string) $value : null;
    }

    public function getOrderId(): ?string
    {
        $value = Registry::getSession()->getVariable(self::SESSION_ORDER_ID);
        return $value ? (string) $value : null;
    }

    public function getPaymentId(): ?string
    {
        $value = Registry::getSession()->getVariable(self::SESSION_PAYMENT_ID);
        return $value ? (string) $value : null;
    }

    public function consumeUrl(): ?string
    {
        $url = $this->getUrl();
        Registry::getSession()->deleteVariable(self::SESSION_URL);
        return $url;
    }
}
