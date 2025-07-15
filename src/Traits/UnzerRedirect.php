<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Traits;

use OxidEsales\Eshop\Core\Registry;

trait UnzerRedirect
{
    /**
     * @return void
     */
    public function errorRedirect()
    {
        Registry::getUtils()->redirect(Registry::getConfig()->getShopHomeUrl() . 'cl=payment&payerror=unzer');
    }
}