<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Service;

use Unzer\UnzerPayment\Constants\Constants;

final class ExpressPaymentMethods
{
    public const PAYPAL = Constants::PAYMENT_METHOD_PREFIX . 'paypal';
    public const GOOGLE_PAY = Constants::PAYMENT_METHOD_PREFIX . 'googlepay';
    public const APPLE_PAY = Constants::PAYMENT_METHOD_PREFIX . 'applepay';

    public static function all(): array
    {
        return [self::PAYPAL, self::GOOGLE_PAY, self::APPLE_PAY];
    }

    public static function isExpressPayment(string $paymentId): bool
    {
        return in_array($paymentId, self::all(), true);
    }
}
