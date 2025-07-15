<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\PaymentMethods;

use Unzer\UnzerPayment\Constants\Constants;

class UnzerPayuPaymentMethod extends AbstractUnzerPaymentMethod
{
    const UNZER_SHORT_CODE = Constants::PAYMENT_METHODS['payu']['short_code'];
    const UNZER_LONG_CODE =  Constants::PAYMENT_METHODS['payu']['long_code'];
    const PAYMENT_METHOD_CODE =  Constants::PAYMENT_METHODS['payu']['payment_method_code'];
    const PAYMENT_METHOD_NAME = Constants::PAYMENT_METHODS['payu']['name'];
    const ALLOWED_COUNTRIES = ['PL', 'CZ'];
}
