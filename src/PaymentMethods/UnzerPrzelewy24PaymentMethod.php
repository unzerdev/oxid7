<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\PaymentMethods;

use Unzer\UnzerPayment\Constants\Constants;

class UnzerPrzelewy24PaymentMethod extends AbstractUnzerPaymentMethod
{
    const UNZER_SHORT_CODE = Constants::PAYMENT_METHODS['przelewy24']['short_code'];
    const UNZER_LONG_CODE =  Constants::PAYMENT_METHODS['przelewy24']['long_code'];
    const PAYMENT_METHOD_CODE =  Constants::PAYMENT_METHODS['przelewy24']['payment_method_code'];
    const PAYMENT_METHOD_NAME = Constants::PAYMENT_METHODS['przelewy24']['name'];
    const ALLOWED_COUNTRIES = ['PL'];
}
