<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\PaymentMethods;

use Unzer\UnzerPayment\Constants\Constants;

class UnzerKlarnaPaymentMethod extends AbstractUnzerPaymentMethod
{
    const UNZER_SHORT_CODE = Constants::PAYMENT_METHODS['klarna']['short_code'];
    const UNZER_LONG_CODE = Constants::PAYMENT_METHODS['klarna']['long_code'];
    const PAYMENT_METHOD_CODE = Constants::PAYMENT_METHODS['klarna']['payment_method_code'];
    const PAYMENT_METHOD_NAME = Constants::PAYMENT_METHODS['klarna']['name'];
    const ALLOWED_COUNTRIES = [
        'AU',
        'AT',
        'BE',
        'CA',
        'CZ',
        'DK',
        'FI',
        'FR',
        'DE',
        'GR',
        'HU',
        'IE',
        'IT',
        'MX',
        'NL',
        'NZ',
        'NO',
        'PL',
        'PT',
        'RO',
        'SK',
        'ES',
        'SE',
        'CH',
        'GB',
        'US',
    ];
}
