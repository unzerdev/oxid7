<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\PaymentMethods;

use Unzer\UnzerPayment\Constants\Constants;

class UnzerPostFinanceEfinancePaymentMethod extends AbstractUnzerPaymentMethod
{
    const UNZER_SHORT_CODE = Constants::PAYMENT_METHODS['post_finance_efinance']['short_code'];
    const UNZER_LONG_CODE =  Constants::PAYMENT_METHODS['post_finance_efinance']['long_code'];
    const PAYMENT_METHOD_CODE =  Constants::PAYMENT_METHODS['post_finance_efinance']['payment_method_code'];
    const PAYMENT_METHOD_NAME = Constants::PAYMENT_METHODS['post_finance_efinance']['name'];
    const ALLOWED_COUNTRIES = ['CH'];
}
