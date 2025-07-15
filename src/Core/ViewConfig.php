<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Core;

use OxidEsales\Eshop\Core\Registry;

class ViewConfig extends ViewConfig_parent
{
    public function getUnzerSessionPaymentInfo(): string
    {
        /** @var string $addPaymentInfo */
        $addPaymentInfo = Registry::getSession()->getVariable('additionalUnzerPaymentInformation') ?? '';
        return $addPaymentInfo;
    }
}
