<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Service;

use OxidEsales\Eshop\Application\Model\DeliverySetList;
use OxidEsales\Eshop\Application\Model\Payment as OxidPayment;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\Registry;

final class ExpressCheckoutStateService
{
    private const SESSION_SELECTED_PAYMENT_ID = '_selected_paymentid';

    public function setPaymentMethod(
        string $paymentMethodId,
        User $user
    ): void {
        $session = Registry::getSession();
        $basket = $session->getBasket();

        if (!$basket) {
            throw new \RuntimeException('Basket not available');
        }

        $session->deleteVariable('paymentid');
        $session->deleteVariable('dynvalue');
        $session->deleteVariable('_selected_paymentid');

        $basket->setPayment(null);

        $shippingId = $this->initializeShippingMethod(
            $user
        );

        /** @var OxidPayment $payment */
        $payment = oxNew(OxidPayment::class);

        if (!$payment->load($paymentMethodId)) {
            throw new \RuntimeException(
                'Could not load payment method: ' . $paymentMethodId
            );
        }

        $dynValue = [];

        if (
            !$payment->isValidPayment(
                $dynValue,
                Registry::getConfig()->getShopId(),
                $user,
                $basket->getPriceForPayment(),
                $shippingId
            )
        ) {
            throw new \RuntimeException(
                'Payment method is not valid for current checkout: '
                . $paymentMethodId
            );
        }

        /*
         * Set shipping first.
         */
        $basket->setShipping(
            $shippingId
        );

        $session->setVariable(
            'sShipSet',
            $shippingId
        );

        $basket->setPayment(
            $paymentMethodId
        );

        $session->setVariable(
            'paymentid',
            $paymentMethodId
        );

        $session->setVariable(
            'dynvalue',
            $dynValue
        );

        $basket->onUpdate();
        $basket->calculateBasket(true);

        if ($basket->getPaymentId() !== $paymentMethodId) {
            throw new \RuntimeException(
                sprintf(
                    'Could not set payment method. Expected "%s", basket contains "%s".',
                    $paymentMethodId,
                    (string) $basket->getPaymentId()
                )
            );
        }
    }


    public function resetCheckoutPaymentState(): void
    {
        $session = Registry::getSession();
        $session->deleteVariable('paymentid');
        $session->deleteVariable('dynvalue');
        $session->deleteVariable('_selected_paymentid');
        $basket = $session->getBasket();
        if ($basket) {
            $basket->setPayment(null);
            $basket->onUpdate();
        }
    }

    public function initializeShippingMethod(
        User $user
    ): string {
        $session = Registry::getSession();
        $basket = $session->getBasket();

        if (!$basket) {
            throw new \RuntimeException(
                'Basket not available'
            );
        }

        $shippingId = $session->getVariable(
            'sShipSet'
        );

        [
            $allSets,
            $shippingId,
            $paymentList
        ] = Registry::get(DeliverySetList::class)
            ->getDeliverySetData(
                $shippingId,
                $user,
                $basket
            );

        if (!$shippingId) {
            throw new \RuntimeException(
                'No shipping method available'
            );
        }

        $session->setVariable(
            'sShipSet',
            $shippingId
        );

        $basket->setShipping(
            $shippingId
        );

        return $shippingId;
    }
}
