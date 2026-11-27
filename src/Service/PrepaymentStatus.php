<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Service;

use OxidEsales\Eshop\Core\DatabaseProvider;
use UnzerSDK\Resources\Payment;
use UnzerSDK\Resources\PaymentTypes\Prepayment;

final class PrepaymentStatus
{
    public static function resolve(Payment $payment, $order): ?string
    {
        $total = (float) $order->getFieldData('oxtotalordersum');
        $amount = $payment->getAmount();
        if (!$payment->getPaymentType() instanceof Prepayment
            || $order->getFieldData('oxpaymenttype') !== 'up_prepayment'
            || $total <= 0 || abs($amount->getTotal() - $total) > 0.001
            || $payment->getCurrency() !== $order->getFieldData('oxcurrency')
            || $payment->isCanceled() || $payment->isPaymentReview()
            || $amount->getCanceled() > 0 || count($payment->getChargebacks())) {
            return null;
        }

        if ($payment->isCompleted() && $amount->getCharged() + 0.001 >= $total) {
            return 'OK';
        }
        if ($payment->isPending()) {
            foreach ($payment->getCharges() as $charge) {
                if ($charge->isPending() && abs($charge->getAmount() - $total) <= 0.001) {
                    return 'PENDING';
                }
            }
        }
        return null;
    }

    public static function updateExistingOrder(Payment $payment, $order): void
    {
        $status = self::resolve($payment, $order);
        if ($status === null) {
            return;
        }
        // A delayed browser return must never undo a webhook settlement.
        $db = DatabaseProvider::getDb();
        $set = $status === 'OK'
            ? "oxtransstatus = 'OK', oxpaid = NOW()"
            : "oxtransstatus = 'PENDING'";
        $db->execute("UPDATE oxorder SET $set WHERE oxid = ? AND oxtransid = ? AND oxstorno = 0
            AND oxpaid = '0000-00-00 00:00:00' AND oxtransstatus IN ('OK', 'NOT_FINISHED', 'PENDING')",
            [$order->getId(), $payment->getId()]);
    }
}
