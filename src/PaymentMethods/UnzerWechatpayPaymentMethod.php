<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\PaymentMethods;

use Unzer\UnzerPayment\Constants\Constants;

class UnzerWechatpayPaymentMethod extends AbstractUnzerPaymentMethod
{
    const UNZER_SHORT_CODE = Constants::PAYMENT_METHODS['wechatpay']['short_code'];
    const UNZER_LONG_CODE =  Constants::PAYMENT_METHODS['wechatpay']['long_code'];
    const PAYMENT_METHOD_CODE =  Constants::PAYMENT_METHODS['wechatpay']['payment_method_code'];
    const PAYMENT_METHOD_NAME = Constants::PAYMENT_METHODS['wechatpay']['name'];
    const ALLOWED_COUNTRIES =  ['AT', 'BE', 'DK', 'FI', 'FR', 'DE', 'ES', 'GB', 'GR', 'HU', 'IE', 'IS', 'IT', 'LI', 'LU', 'MT', 'NL', 'NO', 'PT', 'SE'];
}
