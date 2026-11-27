<?php
namespace Unzer\UnzerPayment\Service;

use OxidEsales\Eshop\Application\Model\Order;
use Unzer\UnzerPayment\Classes\UnzerpaymentHelper;

class BasketService
{

    /**
     * @param $oBasket
     * @param $orderId
     * @return \UnzerSDK\Resources\Basket
     */
    public function getBasket($oBasket, $orderId, ?float $orderTotal = null)
    {
        $totalValueGross = $oBasket->getBruttoSum() - $oBasket->getTotalDiscountSum();
        if ($oBasket->getDeliveryCosts()) {
            $totalValueGross+= $oBasket->getDeliveryCost()->getBruttoPrice();
        }

        $totalValueGross = $orderTotal ?? $totalValueGross;
        $basket = (new \UnzerSDK\Resources\Basket())
            ->setTotalValueGross($totalValueGross)
            ->setCurrencyCode($oBasket->getBasketCurrency()->name)
            ->setOrderId($orderId)
            ->setNote('');

        $basketItems = [];
        $tmpSum = 0;
        foreach ($oBasket->getContents() as $product) {
            $tmpSum += UnzerpaymentHelper::getInstance()->prepareAmountValue($product->getPrice()->getBruttoPrice()) * $product->getAmount();
            $basketItemReferenceId = 'Item-' . $product->getProductId();
            $basketItem = (new \UnzerSDK\Resources\EmbeddedResources\BasketItem())
                ->setBasketItemReferenceId($basketItemReferenceId)
                ->setQuantity((int)$product->getAmount())
                ->setUnit('m')
                ->setAmountPerUnitGross(UnzerpaymentHelper::getInstance()->prepareAmountValue($product->getPrice()->getBruttoPrice()))
                ->setVat((float)$product->getVatPercent())
                ->setTitle($product->getTitle())
                ->setType(\UnzerSDK\Constants\BasketItemTypes::GOODS);

            $basketItems[] = $basketItem;
        }

        if ($oBasket->getDeliveryCosts()) {
            $tmpSum += $oBasket->getDeliveryCost()->getBruttoPrice();
            $basketItem = (new \UnzerSDK\Resources\EmbeddedResources\BasketItem())
                ->setBasketItemReferenceId('Shipping')
                ->setQuantity(1)
                ->setAmountPerUnitGross(UnzerpaymentHelper::getInstance()->prepareAmountValue($oBasket->getDeliveryCost()->getBruttoPrice()))
                ->setTitle('Shipping')
                ->setType(\UnzerSDK\Constants\BasketItemTypes::SHIPMENT);
            $basketItems[] = $basketItem;
        }

        $discountsAmount = $oBasket->getTotalDiscountSum();
        if ($discountsAmount > 0) {
            $tmpSum -= $discountsAmount;
            $basketItem = (new \UnzerSDK\Resources\EmbeddedResources\BasketItem())
                ->setBasketItemReferenceId('Voucher')
                ->setQuantity(1)
                ->setAmountDiscountPerUnitGross(UnzerpaymentHelper::getInstance()->prepareAmountValue($discountsAmount))
                ->setTitle('Voucher Delta')
                ->setType(\UnzerSDK\Constants\BasketItemTypes::VOUCHER);
            $basketItems[] = $basketItem;
        }

        $difference = ((float)$totalValueGross*100 - (float)$tmpSum*100)/100;
        if ($difference > 0 && UnzerpaymentHelper::getInstance()->prepareAmountValue($difference) > 0) {
            $basketItem = (new \UnzerSDK\Resources\EmbeddedResources\BasketItem())
                ->setBasketItemReferenceId('add-shipping-delta')
                ->setQuantity(1)
                ->setAmountPerUnitGross(UnzerpaymentHelper::getInstance()->prepareAmountValue($difference))
                ->setTitle('Shipping')
                ->setSubTitle('Shipping Delta')
                ->setType(\UnzerSDK\Constants\BasketItemTypes::SHIPMENT);
            $basketItems[] = $basketItem;
        } elseif ($difference < 0 && UnzerpaymentHelper::getInstance()->prepareAmountValue($difference) < 0) {
            $basketItem = (new \UnzerSDK\Resources\EmbeddedResources\BasketItem())
                ->setBasketItemReferenceId('VoucherDelta')
                ->setQuantity(1)
                ->setAmountDiscountPerUnitGross(UnzerpaymentHelper::getInstance()->prepareAmountValue($difference) * -1)
                ->setTitle('Voucher Delta')
                ->setType(\UnzerSDK\Constants\BasketItemTypes::VOUCHER);
            $basketItems[] = $basketItem;
        }

        foreach ($basketItems as $basketItem) {
            $basket->addBasketItem(
                $basketItem
            );
        }

        return $basket;
    }

}