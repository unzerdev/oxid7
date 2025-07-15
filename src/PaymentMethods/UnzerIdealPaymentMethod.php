<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\PaymentMethods;

use Unzer\UnzerPayment\Constants\Constants;

class UnzerIdealPaymentMethod extends AbstractUnzerPaymentMethod
{
    const UNZER_SHORT_CODE = Constants::PAYMENT_METHODS['ideal']['short_code'];
    const UNZER_LONG_CODE =  Constants::PAYMENT_METHODS['ideal']['long_code'];
    const PAYMENT_METHOD_CODE =  Constants::PAYMENT_METHODS['ideal']['payment_method_code'];
    const PAYMENT_METHOD_NAME = Constants::PAYMENT_METHODS['ideal']['name'];
    const ALLOWED_COUNTRIES = ['NL'];
}
