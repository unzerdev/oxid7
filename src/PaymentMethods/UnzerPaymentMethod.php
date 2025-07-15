<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\PaymentMethods;


class UnzerPaymentMethod extends AbstractUnzerPaymentMethod
{
    const UNZER_SHORT_CODE = 'epp';
    const UNZER_LONG_CODE = 'embedded_payment_page';
    const PAYMENT_METHOD_CODE = 'UNZER_PAYMENT';
    const PAYMENT_METHOD_NAME = 'Unzer Payments';

}