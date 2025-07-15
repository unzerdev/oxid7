<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\PaymentMethods;

use Unzer\UnzerPayment\Constants\Constants;

class UnzerAlipayPaymentMethod extends AbstractUnzerPaymentMethod
{   const UNZER_SHORT_CODE = Constants::PAYMENT_METHODS['alipay']['short_code'];
    const UNZER_LONG_CODE =  Constants::PAYMENT_METHODS['alipay']['long_code'];
    const PAYMENT_METHOD_CODE =  Constants::PAYMENT_METHODS['alipay']['payment_method_code'];
    const PAYMENT_METHOD_NAME = Constants::PAYMENT_METHODS['alipay']['name'];
    const ALLOWED_COUNTRIES = ['DE', 'AT', 'BE', 'IT', 'ES', 'NL'];
}
