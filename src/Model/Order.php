<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Model;

use Unzer\UnzerPayment\Traits\ServiceContainer;
use OxidEsales\Eshop\Core\Field;
use OxidEsales\EshopCommunity\Internal\Framework\Database\QueryBuilderFactoryInterface;
use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\Counter;
use OxidEsales\Eshop\Core\Registry;
use Unzer\UnzerPayment\Model\PaymentGateway;
use Unzer\UnzerPayment\Service\ExpressCheckoutService;
use Unzer\UnzerPayment\Service\ExpressUserService;
use Unzer\UnzerPayment\Service\RedirectSessionService;

class Order extends Order_parent
{
    use ServiceContainer;

    public function unzerPrepare($basket, $user, string $id): void
    {
        $this->setId($id);
        $state = $this->validateOrder($basket, $user);
        if ($state) {
            throw new \RuntimeException('OXID order validation failed: ' . $state);
        }
        $this->assignUserInformation($user);
        $this->loadFromBasket($basket);
        $this->setPayment($basket->getPaymentId());
        $this->oxorder__oxunzerpayordernr = new Field(\Unzer\UnzerPayment\Constants\Constants::ORDER_ID_PREFIX . $id);
        $this->oxorder__oxfolder = new Field('UNZER_PENDING');
        $this->oxorder__oxtransstatus = new Field('NOT_FINISHED');
        foreach ($this->getOrderArticles() as $article) {
            $article->setIsNewOrderItem(false);
        }
        if (!$this->save()) {
            throw new \RuntimeException('Cannot persist Unzer preliminary order.');
        }
    }

    /** Only called under the service's lock and transaction. Never executes payment or SMTP. */
    public function unzerComplete($basket, $user, string $paymentId, string $status): void
    {
        $db = \OxidEsales\Eshop\Core\DatabaseProvider::getDb(\OxidEsales\Eshop\Core\DatabaseProvider::FETCH_MODE_ASSOC);
        foreach ($this->getOrderArticles() as $article) {
            if ($article->getFieldData('oxstorno')) {
                throw new \RuntimeException('Preliminary order contains cancelled items.');
            }
            $db->getOne('SELECT oxid FROM oxarticles WHERE oxid = ? FOR UPDATE', [$article->getFieldData('oxartid')]);
        }
        $this->validateStock($basket);
        foreach ($this->getOrderArticles() as $article) {
            $article->setIsNewOrderItem(true);
            if (!$article->save()) {
                throw new \RuntimeException('Cannot complete order article.');
            }
        }
        foreach ((array) $basket->getVouchers() as $id => $voucher) {
            $used = $db->getRow('SELECT oxorderid AS orderid, oxdateused AS dateused FROM oxvouchers WHERE oxid = ? FOR UPDATE', [$id]);
            $hasUseDate = !empty($used['dateused']) && $used['dateused'] !== '0000-00-00';
            if (!$used || (($hasUseDate || !empty($used['orderid'])) && $used['orderid'] !== $this->getId())) {
                throw new \RuntimeException('Voucher already consumed by another order.');
            }
        }
        $this->markVouchers($basket, $user);
        $this->updateWishlist($basket->getContents(), $user);
        $this->updateNoticeList($basket->getContents(), $user);
        if (!$this->getFieldData('oxordernr')) {
            $this->setNumber();
        }
        $this->updateOrderDate();
        $this->setFolder();
        $this->oxorder__oxtransid = new Field($paymentId);
        $this->oxorder__oxtransstatus = new Field($status);
        if ($status === 'OK') {
            $this->oxorder__oxpaid = new Field(date('Y-m-d H:i:s'));
        }
        $basket->setOrderId($this->getId());
        $user->onOrderExecute($basket, self::ORDER_STATE_OK);
        if (!$this->save()) {
            throw new \RuntimeException('Cannot complete Unzer order.');
        }
    }

    public function unzerMailContext($basket, $user): void
    {
        $this->_oUser = $user;
        $this->_oBasket = $basket;
        $this->_oPayment = oxNew(\OxidEsales\Eshop\Application\Model\UserPayment::class);
        $this->_oPayment->load($this->getFieldData('oxpaymentid'));
        $this->_aVoucherList = [];
        foreach ((array) $basket->getVouchers() as $id => $unused) {
            $voucher = oxNew(\OxidEsales\Eshop\Application\Model\Voucher::class);
            if ($voucher->load($id)) {
                $this->_aVoucherList[$id] = $voucher;
            }
        }
    }

    public const ORDER_STATE_UNZER_REDIRECT = 1001;

    protected function assignUserInformation($oUser)
    {
        $session = Registry::getSession();
        if ($session->getVariable(ExpressCheckoutService::SESSION_SELECTED_EXPRESS_METHOD)
            && $session->getVariable(ExpressCheckoutService::SESSION_CHECKOUT_USER_ID) !== $oUser->getId()
        ) {
            throw new \RuntimeException('Express checkout user has changed');
        }

        parent::assignUserInformation($oUser);
        if (!ExpressUserService::isExpressGuest($oUser)) {
            return;
        }

        $contact = $session->getVariable(ExpressCheckoutService::SESSION_GUEST_CONTACT);
        $email = $contact['email'] ?? null;
        if (($contact['userId'] ?? null) !== $oUser->getId()
            || $session->getVariable('usr') !== $oUser->getId()
            || !is_string($email)
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            throw new \RuntimeException('Express guest contact does not belong to this checkout');
        }

        $this->oxorder__oxbillemail = new Field($email, Field::T_RAW);
    }

    protected function sendOrderByEmail($oUser = null, $oBasket = null, $oPayment = null)
    {
        // OXID uses the supplied user object as the order email recipient.
        if ($oUser instanceof User && ExpressUserService::isExpressGuest($oUser)
            && $oUser->getId() === $this->getFieldData('oxuserid')
        ) {
            $oUser = clone $oUser;
            $oUser->oxuser__oxusername = new Field($this->getFieldData('oxbillemail'), Field::T_RAW);
        }

        return parent::sendOrderByEmail($oUser, $oBasket, $oPayment);
    }

    public function finalizeOrder(
        Basket $oBasket,
               $oUser,
               $blRecalculatingOrder = false
    ) {
        $id = $this->getId() ?: \OxidEsales\Eshop\Core\Registry::getSession()->getVariable('sess_challenge');
        $row = (new \Unzer\UnzerPayment\Service\PreOrderService())->find('oxid', (string) $id);
        if ($row && $row['state'] !== 'finalized') {
            throw new \RuntimeException('Pending Unzer orders can only be completed by verified payment.');
        }
        $orderId = Registry::getSession()->getVariable(
            'sess_challenge'
        );

        if ($this->checkOrderExist($orderId)) {
            Registry::getLogger()->debug(
                'finalizeOrder: Order already exists: ' . $orderId,
                [$oBasket, $oUser]
            );

            return self::ORDER_STATE_ORDEREXISTS;
        }

        if (!$blRecalculatingOrder) {
            $this->setId($orderId);

            if ($iOrderState = $this->validateOrder(
                $oBasket,
                $oUser
            )) {
                return $iOrderState;
            }
        }

        $this->assignUserInformation(
            $oUser
        );

        $this->loadFromBasket(
            $oBasket
        );

        $oUserPayment = $this->setPayment(
            $oBasket->getPaymentId()
        );

        if (!$blRecalculatingOrder) {
            $this->setFolder();
        }

        $this->setOrderStatus(
            'NOT_FINISHED'
        );

        $this->save();

        if (!$blRecalculatingOrder) {
            $paymentResult = $this->executePayment(
                $oBasket,
                $oUserPayment
            );

            if ($paymentResult !== true) {
                return $paymentResult;
            }

            if (
                (new RedirectSessionService())->isRequired()
            ) {
                return self::ORDER_STATE_UNZER_REDIRECT;
            }
        }

        return $this->finishOrder(
            $oBasket,
            $oUser,
            $oUserPayment,
            $blRecalculatingOrder
        );
    }

    /**
     * Continues a NOT_FINISHED order after successful return from Unzer.
     */
    public function finalizeUnzerRedirectOrder(
        Basket $basket,
        User $user
    ): int {
        if (!$this->getId()) {
            throw new \RuntimeException(
                'Cannot finalize Unzer redirect order without order ID.'
            );
        }

        if (
            (string) $this->oxorder__oxtransstatus->value === 'OK'
        ) {
            $basket->setOrderId(
                $this->getId()
            );

            return self::ORDER_STATE_OK;
        }

        if (
            (string) $this->oxorder__oxtransstatus->value
            !== 'NOT_FINISHED'
        ) {
            throw new \RuntimeException(
                sprintf(
                    'Order %s cannot be resumed from status "%s".',
                    $this->getId(),
                    (string) $this->oxorder__oxtransstatus->value
                )
            );
        }

        $userPayment = $this->getPaymentType();

        if (!$userPayment) {
            throw new \RuntimeException(
                'Could not load payment information for order '
                . $this->getId()
            );
        }

        return $this->finishOrder(
            $basket,
            $user,
            $userPayment,
            false
        );
    }

    /**
     * Second half of OXID's core finalizeOrder().
     */
    protected function finishOrder(
        Basket $basket,
        User $user,
               $userPayment,
        bool $recalculatingOrder = false
    ): int {
        if (
            !isset($this->oxorder__oxordernr->value)
            || !$this->oxorder__oxordernr->value
        ) {
            $this->setNumber();
        } else {
            oxNew(Counter::class)->update(
                $this->getCounterIdent(),
                $this->oxorder__oxordernr->value
            );
        }

        Registry::getSession()->deleteVariable(
            'ordrem'
        );

        if (!$recalculatingOrder) {
            $this->updateOrderDate();
        }

        $this->setOrderStatus(
            'OK'
        );

        /*
         * This is important for ThankYouController.
         */
        $basket->setOrderId(
            $this->getId()
        );

        $this->updateWishlist(
            $basket->getContents(),
            $user
        );

        $this->updateNoticeList(
            $basket->getContents(),
            $user
        );

        if (!$recalculatingOrder) {
            $this->markVouchers(
                $basket,
                $user
            );
        }

        if (!$recalculatingOrder) {
            return $this->sendOrderByEmail(
                $user,
                $basket,
                $userPayment
            );
        }

        return self::ORDER_STATE_OK;
    }

    public function getUnzerOrderNr(): string
    {
        $value = $this->getFieldData('oxunzerpayordernr');
        return is_string($value) ? $value : '';
    }

    /**
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function setUnzerOrderNr(string $unzerOrderId): string
    {
        /** @var QueryBuilderFactoryInterface $queryBuilderFactory */
        $queryBuilderFactory = $this->getServiceFromContainer(QueryBuilderFactoryInterface::class);

        $queryBuilder = $queryBuilderFactory->create();

        $query = $queryBuilder
            ->update('oxorder')
            ->set("oxunzerpayordernr", ":oxunzerpayordernr")
            ->where("oxid = :oxid");

        $parameters = [
            ':oxunzerpayordernr' => $unzerOrderId,
            ':oxid' => $this->getId()
        ];

        $query->setParameters($parameters)->execute();
        $this->oxorder__oxunzerpayordernr = new Field($unzerOrderId);

        return $unzerOrderId;
    }

}