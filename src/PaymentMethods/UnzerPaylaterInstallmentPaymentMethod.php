<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\PaymentMethods;

use Unzer\UnzerPayment\Constants\Constants;

class UnzerPaylaterInstallmentPaymentMethod extends AbstractUnzerPaymentMethod
{
    const UNZER_SHORT_CODE = Constants::PAYMENT_METHODS['paylater_installment']['short_code'];
    const UNZER_LONG_CODE =  Constants::PAYMENT_METHODS['paylater_installment']['long_code'];
    const PAYMENT_METHOD_CODE =  Constants::PAYMENT_METHODS['paylater_installment']['payment_method_code'];
    const PAYMENT_METHOD_NAME = Constants::PAYMENT_METHODS['paylater_installment']['name'];
    const ALLOWED_COUNTRIES = ['DE', 'AT', 'CH'];
}
