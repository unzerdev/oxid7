<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\PaymentMethods;

use Unzer\UnzerPayment\Constants\Constants;

class UnzerPaylaterInvoicePaymentMethod extends AbstractUnzerPaymentMethod
{
    const UNZER_SHORT_CODE = Constants::PAYMENT_METHODS['paylater_invoice']['short_code'];
    const UNZER_LONG_CODE =  Constants::PAYMENT_METHODS['paylater_invoice']['long_code'];
    const PAYMENT_METHOD_CODE =  Constants::PAYMENT_METHODS['paylater_invoice']['payment_method_code'];
    const PAYMENT_METHOD_NAME = Constants::PAYMENT_METHODS['paylater_invoice']['name'];
    const ALLOWED_COUNTRIES = ['DE', 'AT', 'CH', 'NL'];
}
