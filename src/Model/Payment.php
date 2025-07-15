<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Model;

use Unzer\UnzerPayment\Classes\UnzerpaymentHelper;

class Payment extends Payment_parent
{
    /**
     * @return bool
     */
    public function isUnzerPaymentMethod()
    {
        return UnzerpaymentHelper::getInstance()->isUnzerPaymentMethod($this->getId());
    }

    /**
     * Return Unzer payment model
     *
     * @return \Unzer\UnzerPayment\PaymentMethods\AbstractUnzerPaymentMethod
     */
    public function getUnzerPaymentMethodModel()
    {
        if ($this->isUnzerPaymentMethod()) {
            return UnzerpaymentHelper::getInstance()->getUnzerPaymentMethodModel($this->getId());
        }
        return null;
    }

    /**
     * @return string
     */
    public function getIcon()
    {
        if ($this->isUnzerPaymentMethod()) {
            return $this->getUnzerPaymentMethodModel()->getIcon();
        }
        return null;
    }
}