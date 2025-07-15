<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\PaymentMethods;

use Unzer\UnzerPayment\Constants\Constants;

class UnzerBancontactPaymentMethod extends AbstractUnzerPaymentMethod
{
    const UNZER_SHORT_CODE = Constants::PAYMENT_METHODS['bancontact']['short_code'];
    const UNZER_LONG_CODE =  Constants::PAYMENT_METHODS['bancontact']['long_code'];
    const PAYMENT_METHOD_CODE =  Constants::PAYMENT_METHODS['bancontact']['payment_method_code'];
    const PAYMENT_METHOD_NAME = Constants::PAYMENT_METHODS['bancontact']['name'];
    const ALLOWED_COUNTRIES = ['BE'];
}
