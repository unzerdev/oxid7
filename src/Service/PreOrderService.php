<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Service;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Core\Di\ContainerFacade;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;
use Unzer\UnzerPayment\Classes\UnzerpaymentClient;
use Unzer\UnzerPayment\Constants\Constants;

class PreOrderService
{
    /** @var DebugHandler|null */
    private $preOrderLogger;
    /** @var string|null Correlates all service instances in this HTTP request. */
    private static $logRequestId;


    private function log(string $event, array $context = [], int $level = 3, ?\Throwable $exception = null): void
    {
        $originalUri = $_SERVER['REQUEST_URI'] ?? null;
        try {
            if ($this->preOrderLogger === null) {
                $this->preOrderLogger = ContainerFacade::get(DebugHandler::class);
            }
            if (self::$logRequestId === null) {
                self::$logRequestId = uniqid('preorder-', true);
            }
            $context = array_merge([
                'request_id' => self::$logRequestId,
                'shop_id' => (string) Registry::getConfig()->getShopId(),
            ], $context);
            if ($exception !== null) {
                $context['exception_class'] = get_class($exception);
                $context['exception_code'] = $exception->getCode();
                $context['exception_file'] = $exception->getFile();
                $context['exception_line'] = $exception->getLine();
            }
            $path = parse_url((string) $originalUri, PHP_URL_PATH);
            $_SERVER['REQUEST_URI'] = is_string($path) ? $path : '';
            $this->preOrderLogger->addLog('[PreOrderService] ' . $event, $level, false, $context);
        } catch (\Throwable $loggingFailure) {
            // Diagnostics must never turn a successful payment into an error or mask its exception.
        } finally {
            if ($originalUri === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $originalUri;
            }
        }
    }

    private function fetchPayment(string $id)
    {
        $this->log('api.payment_fetch_start', ['payment_id' => $id]);
        try {
            $payment = UnzerpaymentClient::getInstance()->fetchPayment($id);
            $this->log('api.payment_fetch_complete', ['payment_id' => $id]);
            return $payment;
        } catch (\Throwable $e) {
            $this->log('api.payment_fetch_failed', ['payment_id' => $id], 1, $e);
            throw $e;
        }
    }

    private function fetchPaypage(string $id)
    {
        $this->log('api.paypage_fetch_start', ['paypage_id' => $id]);
        try {
            $page = UnzerpaymentClient::getInstance()->fetchPaypageV2($id);
            $this->log('api.paypage_fetch_complete', ['paypage_id' => $id, 'payment_count' => count((array) $page->getPayments())]);
            return $page;
        } catch (\Throwable $e) {
            $this->log('api.paypage_fetch_failed', ['paypage_id' => $id], 1, $e);
            throw $e;
        }
    }

    public static function enabled(): bool
    {
        return (bool) ContainerFacade::get(ModuleSettingServiceInterface::class)->getBoolean('blUnzerPreCreateOrder', Constants::MODULE_ID);
    }

    public static function tableExists(): bool
    {
        static $exists;
        if ($exists === null) {
            $exists = (bool) DatabaseProvider::getDb()->getOne("SHOW TABLES LIKE 'unzer_preorders'");
        }
        return $exists;
    }

    public function find(string $column, string $value): array
    {
        if ($value === '' || !in_array($column, ['oxid', 'reference', 'paypageid', 'paymentid'], true) || !self::tableExists()) {
            return [];
        }
        $row = DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC)->getRow(
            'SELECT * FROM unzer_preorders WHERE ' . $column . ' = ? AND shopid = ?',
            [$value, (string) Registry::getConfig()->getShopId()]
        ) ?: [];
        $this->log('lookup.result', ['lookup_column' => $column, 'lookup_value' => $value, 'found' => (bool) $row, 'order_id' => $row['oxid'] ?? null, 'state' => $row['state'] ?? null]);
        return $row;
    }

    public function prepare($basket, $user): array
    {
        $this->log('prepare.start');
        $this->assertTransactionalTables();
        $db = DatabaseProvider::getDb();
        $session = Registry::getSession();
        $basket->calculateBasket(true);
        $items = [];
        foreach ($basket->getContents() as $key => $item) {
            $items[$key] = [$item->getProductId(), $item->getAmount(), $item->getPersParams(),
                $item->getWrappingId(), $item->getPrice()->getBruttoPrice()];
        }
        $fingerprint = hash('sha256', serialize([$items, $user->getId(), $user->getEncodedDeliveryAddress(),
            Registry::getRequest()->getRequestParameter('sDeliveryAddressMD5'), $basket->getPaymentId(),
            $basket->getShippingId(), $basket->getBasketCurrency()->name, $basket->getPrice()->getBruttoPrice(),
            $basket->getVouchers(), $session->getVariable('ordrem'), $basket->getCardId(), $basket->getCardMessage()]));
        $id = (string) $session->getVariable('sess_challenge');
        if (!$id) {
            $id = Registry::getUtilsObject()->generateUID();
            $session->setVariable('sess_challenge', $id);
        }
        $this->log('prepare.validated_basket', ['order_id' => $id, 'item_count' => count($items), 'amount' => $basket->getPrice()->getBruttoPrice(), 'currency' => $basket->getBasketCurrency()->name, 'payment_method' => $basket->getPaymentId()]);
        return $this->locked($id, function () use ($id, $fingerprint, $basket, $user, $db) {
            $existing = $this->find('oxid', $id);
            if ($existing) {
                if ($existing['fingerprint'] !== $fingerprint || $existing['userid'] !== $user->getId()) {
                    $this->log('prepare.existing_context_mismatch', ['order_id' => $id, 'fingerprint_matches' => $existing['fingerprint'] === $fingerprint, 'user_matches' => $existing['userid'] === $user->getId()], 2);
                    throw new \RuntimeException('Checkout changed. Return to payment selection to start a new attempt.');
                }
                $this->log('prepare.reused', ['order_id' => $id, 'state' => $existing['state']]);
                return $existing;
            }
            if ($db->getOne('SELECT oxid FROM oxorder WHERE oxid = ?', [$id])) {
                throw new \RuntimeException('Checkout already completed.');
            }
            $this->log('prepare.transaction_start', ['order_id' => $id]);
            $db->startTransaction();
            try {
                $order = oxNew(Order::class);
                $order->unzerPrepare($basket, $user, $id);
                $this->log('prepare.order_saved', ['order_id' => $id]);

                $snapshot = clone $basket;
                $snapshot->enableSaveToDataBase(false);
                $snapshot->setBasketUser(null);
                $db->execute('INSERT INTO unzer_preorders
                    (oxid, shopid, userid, reference, fingerprint, basket, reservationtoken, created) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
                    [$id, (string) Registry::getConfig()->getShopId(), $user->getId(), Constants::ORDER_ID_PREFIX . $id,
                        $fingerprint, base64_encode(serialize($snapshot)), (string) Registry::getSession()->getVariable('basketReservationToken')]);
                $db->commitTransaction();
                $this->log('prepare.committed', ['order_id' => $id, 'reference' => Constants::ORDER_ID_PREFIX . $id]);
            } catch (\Throwable $e) {
                $this->log('prepare.rollback', ['order_id' => $id], 1, $e);
                $db->rollbackTransaction();
                throw $e;
            }
            return $this->find('oxid', $id);
        });
    }

    public function attach(string $id, string $paypageId): void
    {
        $this->log('attach.start', ['order_id' => $id, 'paypage_id' => $paypageId]);
        if ($paypageId === '') {
            throw new \RuntimeException('Unzer did not create a PaymentPage.');
        }
        DatabaseProvider::getDb()->execute(
            'UPDATE unzer_preorders SET paypageid = ? WHERE oxid = ? AND paypageid IS NULL AND shopid = ?',
            [$paypageId, $id, (string) Registry::getConfig()->getShopId()]
        );
        if (($this->find('oxid', $id)['paypageid'] ?? '') !== $paypageId) {
            $this->log('attach.binding_conflict', ['order_id' => $id, 'paypage_id' => $paypageId], 1);
            throw new \RuntimeException('PaymentPage already bound to another attempt.');
        }
        $this->log('attach.complete', ['order_id' => $id, 'paypage_id' => $paypageId]);
    }

    /** Null: legacy payment; false: pending; empty string: terminal negative; id: completed. */
    public function fromPayment(string $paymentId, bool $prepaymentOnly = false)
    {
        $this->log('webhook.start', ['payment_id' => $paymentId]);
        if (!DatabaseProvider::getDb()->getOne('SELECT oxid FROM unzer_preorders WHERE shopid = ? LIMIT 1', [(string) Registry::getConfig()->getShopId()])) {
            $this->log('webhook.no_preorders', ['payment_id' => $paymentId]);
            return null;
        }
        $payment = $this->fetchPayment($paymentId);
        if ($prepaymentOnly && !($payment->getPaymentType() instanceof \UnzerSDK\Resources\PaymentTypes\Prepayment)) {
            return null;
        }
        $row = $this->find('paymentid', $paymentId);
        if (!$row) {
            $row = $this->find('reference', (string) $payment->getOrderId());
        }
        if (!$row) {
            $basket = $payment->getBasket();
            $row = $basket ? $this->find('reference', (string) $basket->getOrderId()) : [];
        }
        $this->log('webhook.resolved', ['payment_id' => $paymentId, 'order_id' => $row['oxid'] ?? null, 'found' => (bool) $row]);
        return $row ? $this->complete($row['oxid'], $paymentId) : null;
    }

    public function fromPaypage(string $id)
    {
        $this->log('return.start', ['order_id' => $id]);
        $row = $this->find('oxid', $id);
        if (!$row || !$row['paypageid']) {
            $this->log('return.no_binding', ['order_id' => $id], 2);
            return false;
        }
        if ($row['paymentid']) {
            $this->log('return.reused_payment', ['order_id' => $id, 'payment_id' => $row['paymentid']]);
            return $this->complete($id, $row['paymentid']);
        }
        $page = $this->fetchPaypage($row['paypageid']);
        $this->log('return.page_loaded', ['order_id' => $id, 'paypage_id' => $row['paypageid'], 'payment_count' => count((array) $page->getPayments())]);
        foreach ((array) $page->getPayments() as $entry) {
            if ($this->complete($id, $entry->getPaymentId())) {
                return $id;
            }
        }
        return false;
    }

    public function complete(string $id, string $paymentId)
    {
        $this->log('complete.start', ['order_id' => $id, 'payment_id' => $paymentId]);
        return $this->locked($id, function () use ($id, $paymentId) {
            $db = DatabaseProvider::getDb();
            $row = $this->find('oxid', $id);
            if (!$row || !$row['paypageid']) {
                throw new \RuntimeException('Preliminary order has no linked PaymentPage yet. Retry webhook.');
            }
            if ($row['paymentid'] && $row['paymentid'] !== $paymentId) {
                throw new \RuntimeException('Multiple payments for one order require manual review.');
            }
            $order = oxNew(Order::class);
            if (!$order->load($id) || $order->getFieldData('oxstorno')) {
                throw new \RuntimeException('Preliminary order is missing or cancelled.');
            }
            $this->log('complete.context_loaded', ['order_id' => $id, 'payment_id' => $paymentId, 'paypage_id' => $row['paypageid'], 'state' => $row['state']]);
            $status = $this->verifiedStatus($row, $order, $paymentId);
            $this->log('complete.verified_status', ['order_id' => $id, 'payment_id' => $paymentId, 'status' => $status]);
            if (!$status) {
                $this->log('complete.waiting', ['order_id' => $id, 'payment_id' => $paymentId]);
                return false;
            }
            if (in_array($status, ['CANCELED', 'CHARGEBACK'], true)) {
                if ($row['state'] === 'finalized') {
                    $db->execute('UPDATE oxorder SET oxtransstatus = ? WHERE oxid = ?', [$status, $id]);
                }
                $this->log('complete.terminal_status', ['order_id' => $id, 'status' => $status, 'was_finalized' => $row['state'] === 'finalized']);
                return '';
            }
            $basket = unserialize(base64_decode($row['basket'], true));
            if (!$basket instanceof \OxidEsales\Eshop\Application\Model\Basket) {
                throw new \RuntimeException('Invalid stored basket snapshot.');
            }
            $user = oxNew(User::class);
            if (!$user->load($row['userid'])) {
                throw new \RuntimeException('Order customer no longer exists.');
            }
            $basket->setBasketUser($user);
            $previousUser = $order->getUser();
            $order->setUser($user);
            $config = Registry::getConfig();
            $reservation = $config->getConfigParam('blPsBasketReservationEnabled');
            $language = Registry::getLang()->getBaseLanguage();
            Registry::getLang()->setBaseLanguage((int) $order->getFieldData('oxlang'));

            $config->setConfigParam('blPsBasketReservationEnabled', false);
            try {
                $this->log('complete.transaction_start', ['order_id' => $id]);
                $db->startTransaction();
                try {
                    $db->getOne('SELECT oxid FROM oxorder WHERE oxid = ? FOR UPDATE', [$id]);
                    $db->getOne('SELECT oxid FROM unzer_preorders WHERE oxid = ? FOR UPDATE', [$id]);
                    $this->log('complete.rows_locked', ['order_id' => $id]);
                    if ($row['state'] !== 'finalized') {
                        $db->execute("UPDATE unzer_preorders SET state = 'completing', paymentid = ? WHERE oxid = ?", [$paymentId, $id]);
                        $this->log('complete.effects_start', ['order_id' => $id, 'status' => $status]);
                        if ($row['reservationtoken']) {
                            $this->log('complete.release_reservation', ['order_id' => $id]);
                            oxNew(\OxidEsales\Eshop\Application\Model\BasketReservation::class)->unzerDiscardToken($row['reservationtoken']);
                            Registry::getSession()->getBasketReservations()->unzerResetCache();
                        }
                        $order->unzerComplete($basket, $user, $paymentId, $status);
                        $this->log('complete.effects_finished', ['order_id' => $id]);
                        $db->execute("UPDATE unzer_preorders SET state = 'finalized', finalized = NOW() WHERE oxid = ?", [$id]);
                    } elseif ($status === 'OK') {
                        $this->log('complete.capture_status_only', ['order_id' => $id, 'payment_id' => $paymentId]);
                        $db->execute("UPDATE oxorder SET oxtransstatus = 'OK', oxpaid = IF(oxpaid = '0000-00-00 00:00:00', NOW(), oxpaid) WHERE oxid = ? AND oxtransstatus IN ('PENDING', 'AUTHORIZED', 'OK')", [$id]);
                    }
                    $db->commitTransaction();
                    $this->log('complete.committed', ['order_id' => $id, 'status' => $status, 'effects_skipped' => $row['state'] === 'finalized']);
                } catch (\Throwable $e) {
                    $this->log('complete.rollback', ['order_id' => $id, 'payment_id' => $paymentId], 1, $e);
                    $db->rollbackTransaction();
                    throw $e;
                }
                $order->load($id);
                $basket->setOrderId($id);
                $this->sendMails($order, $basket, $user);
                $this->log('complete.finished', ['order_id' => $id, 'payment_id' => $paymentId, 'status' => $status]);
                return $id;
            } finally {
                $config->setConfigParam('blPsBasketReservationEnabled', $reservation);
                Registry::getLang()->setBaseLanguage($language);
                $order->setUser($previousUser);
            }
        });
    }

    private function verifiedStatus(array $row, $order, string $paymentId): ?string
    {
        $page = $this->fetchPaypage($row['paypageid']);
        $linked = false;
        foreach ((array) $page->getPayments() as $entry) {
            $linked = $linked || $entry->getPaymentId() === $paymentId;
        }
        $payment = $this->fetchPayment($paymentId);
        $expected = (float) $order->getFieldData('oxtotalordersum');
        $diagnostics = ['order_id' => $row['oxid'] ?? null, 'paypage_id' => $row['paypageid'], 'payment_id' => $paymentId,
            'linked' => $linked, 'expected_amount' => $expected, 'payment_amount' => $payment->getAmount()->getTotal(),
            'amount_difference' => abs($payment->getAmount()->getTotal() - $expected),
            'expected_currency' => $order->getFieldData('oxcurrency'), 'payment_currency' => $payment->getCurrency(),
            'page_order_reference' => $page->getOrderId(), 'page_amount' => $page->getAmount(), 'page_currency' => $page->getCurrency()];
        $this->log('verify.payment_loaded', $diagnostics);

        if (!$linked
            || ($page->getOrderId() && $page->getOrderId() !== $row['reference'])
            || ($page->getCurrency() && $page->getCurrency() !== $order->getFieldData('oxcurrency'))
            || ($page->getAmount() > 0 && abs($page->getAmount() - $expected) > 0.001)
            || $payment->getCurrency() !== $order->getFieldData('oxcurrency')
            || abs($payment->getAmount()->getTotal() - $expected) > 0.001) {
            $this->log('verify.identity_amount_currency_mismatch', $diagnostics, 1);
            throw new \RuntimeException('Unzer payment/order identity, amount or currency mismatch.');
        }
        $basket = $payment->getBasket();
        $references = [$payment->getOrderId(), $basket ? $basket->getOrderId() : null];
        $this->log('verify.references', ['payment_id' => $paymentId, 'expected_reference' => $row['reference'], 'payment_reference' => $references[0], 'basket_reference' => $references[1]]);
        foreach ($references as $reference) {
            if ($reference !== null && $reference !== '' && $reference !== $row['reference']) {
                $this->log('verify.reference_mismatch', ['payment_id' => $paymentId, 'expected_reference' => $row['reference'], 'actual_reference' => $reference], 1);
                throw new \RuntimeException('Unzer payment/order reference mismatch.');
            }
        }
        $this->log('verify.payment_state', ['payment_id' => $paymentId, 'chargeback_count' => count($payment->getChargebacks()), 'canceled' => $payment->isCanceled(), 'review' => $payment->isPaymentReview(), 'canceled_amount' => $payment->getAmount()->getCanceled()]);
        if (count($payment->getChargebacks())) {
            return 'CHARGEBACK';
        }
        if ($payment->isCanceled()) {
            return 'CANCELED';
        }
        if ($payment->isPaymentReview() || $payment->getAmount()->getCanceled() > 0) {
            return null;
        }
        if ($payment->getPaymentType() instanceof \UnzerSDK\Resources\PaymentTypes\Prepayment
            || $order->getFieldData('oxpaymenttype') === 'up_prepayment') {
            return PrepaymentStatus::resolve($payment, $order);
        }
        $charged = 0.0;
        $pending = 0.0;
        foreach ($payment->getCharges() as $index => $charge) {
            $this->log('verify.charge', ['payment_id' => $paymentId, 'charge_index' => $index, 'amount' => $charge->getAmount(), 'success' => $charge->isSuccess()]);
            if ($charge->isSuccess()) {
                $charged += $charge->getAmount();
            } elseif ($charge->isPending()) {
                $pending += $charge->getAmount();
            }
        }
        $this->log('verify.charge_total', ['payment_id' => $paymentId, 'successful_charge_total' => $charged, 'expected_amount' => $expected]);
        if ($charged + 0.001 >= $expected && $charged > 0) {
            return 'OK';
        }
        if ($payment->getPaymentType() instanceof \UnzerSDK\Resources\PaymentTypes\OpenbankingPis
            && $payment->isCompleted()
            && $expected > 0
            && $pending > 0
            && $charged + $pending + 0.001 >= $expected
        ) {
            $this->log('verify.openbanking_completed_pending_charge', [
                'payment_id' => $paymentId,
                'successful_charge_total' => $charged,
                'pending_charge_total' => $pending,
            ]);
            return 'PENDING';
        }
        $authorization = $payment->getAuthorization();
        $this->log('verify.authorization', ['payment_id' => $paymentId, 'present' => (bool) $authorization, 'amount' => $authorization ? $authorization->getAmount() : null, 'success' => $authorization ? $authorization->isSuccess() : null]);
        if ($authorization && $authorization->isSuccess() && $authorization->getAmount() + 0.001 >= $expected) {
            return 'AUTHORIZED';
        }
        return null;
    }

    private function sendMails($order, $basket, $user): void
    {
        $this->log('mail.context_start', ['order_id' => $order->getId()]);
        $order->unzerMailContext($basket, $user);
        $this->setPaymentInformation($order);
        $db = DatabaseProvider::getDb();
        foreach (['mailuser' => 'sendOrderEMailToUser', 'mailowner' => 'sendOrderEMailToOwner'] as $column => $method) {

            if (!$db->execute("UPDATE unzer_preorders SET $column = 'sending' WHERE oxid = ? AND $column = 'new'", [$order->getId()])) {
                $this->log('mail.skipped_existing_claim', ['order_id' => $order->getId(), 'recipient_role' => $column]);
                continue;
            }
            $this->log('mail.claimed', ['order_id' => $order->getId(), 'recipient_role' => $column]);
            try {
                $sent = oxNew(\OxidEsales\Eshop\Core\Email::class)->$method($order);
                $db->execute("UPDATE unzer_preorders SET $column = ? WHERE oxid = ?", [$sent ? 'sent' : 'failed', $order->getId()]);
                $this->log('mail.result', ['order_id' => $order->getId(), 'recipient_role' => $column, 'sent' => (bool) $sent], $sent ? 3 : 1);
            } catch (\Throwable $e) {
                $db->execute("UPDATE unzer_preorders SET $column = 'failed' WHERE oxid = ?", [$order->getId()]);
                $this->log('mail.failed', ['order_id' => $order->getId(), 'recipient_role' => $column], 1, $e);
            }
        }
    }

    private function setPaymentInformation($order): void
    {
        $this->log('payment_information.start', ['order_id' => $order->getId()]);
        try {
            $payment = $this->fetchPayment((string) $order->getFieldData('oxtransid'));
            $transaction = $payment->getInitialTransaction();
            if ($transaction && method_exists($transaction, 'getIban') && $transaction->getIban()) {
                $params = ['unzer_amount' => $transaction->getAmount(), 'unzer_currency' => $transaction->getCurrency(),
                    'unzer_account_holder' => $transaction->getHolder(), 'unzer_account_iban' => $transaction->getIban(),
                    'unzer_account_bic' => $transaction->getBic(), 'unzer_account_descriptor' => $transaction->getDescriptor()];
                $renderer = ContainerFacade::get(\OxidEsales\EshopCommunity\Internal\Framework\Templating\TemplateRendererBridgeInterface::class)->getTemplateRenderer();
                Registry::getSession()->setVariable('additionalUnzerPaymentInformation',
                    $renderer->renderTemplate('@unzer_payment/_inc/transfer_data', $params));
            }
            $this->log('payment_information.prepared', ['order_id' => $order->getId()]);
            $metadata = $payment->getMetadata();
            if ($metadata) {
                $metadata->addMetadata('shopOrderId', $order->getId());
                $metadata->addMetadata('shopOrderReference', (string) $order->getFieldData('oxordernr'));
                UnzerpaymentClient::getInstance()->getResourceService()->updateResource($metadata);
                $this->log('payment_information.metadata_updated', ['order_id' => $order->getId()]);
            }
        } catch (\Throwable $e) {
            $this->log('payment_information.failed', ['order_id' => $order->getId()], 1, $e);
        }
    }

    private function locked(string $id, callable $callback)
    {
        $db = DatabaseProvider::getDb();
        $name = 'unzer-pre-' . md5((string) Registry::getConfig()->getConfigParam('dbName') . ':' . $id);
        $this->log('lock.wait', ['order_id' => $id, 'timeout_seconds' => 15]);
        $started = microtime(true);
        if ((int) $db->getOne('SELECT GET_LOCK(?, 15)', [$name]) !== 1) {
            $this->log('lock.timeout', ['order_id' => $id, 'wait_ms' => (int) round((microtime(true) - $started) * 1000)], 2);
            throw new \RuntimeException('Order currently processing; retry.');
        }
        $this->log('lock.acquired', ['order_id' => $id, 'wait_ms' => (int) round((microtime(true) - $started) * 1000)]);
        try {
            return $callback();
        } catch (\Throwable $e) {
            $this->log('operation.failed', ['order_id' => $id], 1, $e);
            throw $e;
        } finally {
            $released = $db->getOne('SELECT RELEASE_LOCK(?)', [$name]);
            $this->log('lock.released', ['order_id' => $id, 'released' => (int) $released === 1]);
        }
    }

    private function assertTransactionalTables(): void
    {
        $tables = ['oxorder', 'oxorderarticles', 'oxarticles', 'oxvouchers', 'oxuserpayments',
            'oxuser', 'oxuserbaskets', 'oxuserbasketitems', 'oxobject2group', 'oxorderfiles', 'oxcounters', 'unzer_preorders'];
        $db = DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC);
        foreach ($tables as $table) {
            $engine = $db->getOne('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
            $this->log('storage.table_checked', ['table' => $table, 'engine' => $engine]);
            if ($engine && strcasecmp($engine, 'InnoDB') !== 0) {
                $this->log('storage.invalid_engine', ['table' => $table, 'engine' => $engine], 1);
                throw new \RuntimeException('Unzer preliminary orders require InnoDB: ' . $table);
            }
        }
        if (!self::tableExists()) {
            $this->log('storage.migration_missing', [], 1);
            throw new \RuntimeException('Activate the module to install the preliminary-order migration.');
        }
    }
}
