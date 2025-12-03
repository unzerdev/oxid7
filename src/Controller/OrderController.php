<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Controller;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Core\Di\ContainerFacade;
use OxidEsales\Eshop\Core\ShopVersion;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;
use OxidEsales\Facts\Facts;
use Unzer\UnzerPayment\Classes\UnzerpaymentClient;
use Unzer\UnzerPayment\Classes\UnzerpaymentHelper;
use Unzer\UnzerPayment\Constants\Constants;
use UnzerSDK\Constants\CompanyCommercialSectorItems;
use UnzerSDK\Constants\CompanyRegistrationTypes;
use UnzerSDK\Constants\PaypageCheckoutTypes;
use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Application\Model\Country;
use Unzer\UnzerPayment\Service\DebugHandler;
use Unzer\UnzerPayment\Traits\ServiceContainer;
use Unzer\UnzerPayment\Traits\UnzerRedirect;

/**
 * @inheritDoc
 *
 * @extends \OxidEsales\Eshop\Application\Controller\OrderController
 */
class OrderController extends OrderController_parent
{
    use UnzerRedirect;
    use ServiceContainer;

    private $validUnzerPayment = false;
    private DebugHandler $logger;

    /**
     * @return void
     */
    public function init()
    {
        parent::init();

        $this->logger = $this->getServiceFromContainer(DebugHandler::class);

        if (UnzerpaymentClient::getInstance()) {
            if ($payment = $this->getPayment()) {
                if ($payment->isUnzerPaymentMethod()) {
                    $this->validUnzerPayment = true;
                }
            }
        }
    }

    /**
     * @return mixed
     */
    public function unzerPayment()
    {
        $session = Registry::getSession();
        $UnzerPaypageId = $session->getVariable('UnzerPaypageId');
        $UnzerSelectedPaymentMethod = $session->getVariable('UnzerSelectedPaymentMethod');
        if (!$UnzerPaypageId || !$UnzerSelectedPaymentMethod) {
            $this->errorRedirect();
        }

        $paypage = UnzerpaymentClient::getInstance()->fetchPaypageV2($UnzerPaypageId);
        $payment = $paypage->getPayments()[0];

        $this->logger->addLog('Fetched payment', 3,false, [$payment]);

        $payment_detailed = UnzerpaymentClient::getInstance()->fetchPayment(
            $payment->getPaymentId()
        );

        $this->logger->addLog('Fetched payment detailed', 3,false, [$payment_detailed]);

        if ($payment_detailed->isCanceled() || !UnzerpaymentHelper::getInstance()->isValidState($payment->getTransactionStatus())) {
            $this->logger->addLog('Invalid payment state', 1, false, ['payment' => $payment, 'payment_detailed' => $payment_detailed]);
            $this->errorRedirect();
        }

        return $this->execute();
    }

    /**
     * @return mixed
     * @throws \UnzerSDK\Exceptions\UnzerApiException
     */
    public function render()
    {
        if ($this->validUnzerPayment) {
            $this->requestUnzerPayPage();
        }
        Registry::getSession()->deleteVariable('additionalUnzerPaymentInformation');
        return parent::render();
    }

    /**
     * @return void
     * @throws \UnzerSDK\Exceptions\UnzerApiException
     */
    private function requestUnzerPayPage()
    {
        $moduleSettingService = ContainerFacade::get(ModuleSettingServiceInterface::class);
        $unzerPublicKey = $moduleSettingService->getString('UnzerPaymentPublicKey', Constants::MODULE_ID);
        $this->addTplParam('unzerPubKey', $unzerPublicKey);

        $lang = Registry::getLang();
        $iLang = $lang->getBaseLanguage();
        $sLang = $lang->getLanguageAbbr($iLang);
        $this->addTplParam('unzerLocale', $sLang);

        // Create paypageV2 Data
        /* @var $selectedPaymentMethod \Unzer\UnzerPayment\PaymentMethods\AbstractUnzerPaymentMethod  */
        $selectedPaymentMethod = $this->getPayment()->getUnzerPaymentMethodModel();

        /* @var $oUser \OxidEsales\Eshop\Application\Model\User */
        $oUser = $this->getUser();

        $country = oxNew(Country::class);
        $country->load($oUser->oxuser__oxcountryid->value);

        $deliveryCompanyName = '';

        $unzerAddressBilling = (new \UnzerSDK\Resources\EmbeddedResources\Address())
            ->setName($oUser->oxuser__oxfname->value . ' ' . $oUser->oxuser__oxlname->value)
            ->setStreet($oUser->oxuser__oxstreet->value . ' ' . $oUser->oxuser__oxstreetnr->value)
            ->setZip($oUser->oxuser__oxzip->value)
            ->setCity($oUser->oxuser__oxcity->value)
            ->setCountry($country->oxcountry__oxisoalpha2->value);

        if (is_null($this->getDelAddress())) {
            $unzerAddressDelivery = clone $unzerAddressBilling;
            $unzerAddressDelivery->setShippingType(
                \UnzerSDK\Constants\ShippingTypes::EQUALS_BILLING
            );

        } else {
            $oDeliveryAddress = $this->getDelAddress();
            $oDeliveryCountry = oxNew(Country::class);
            $oDeliveryCountry->load($oDeliveryAddress->oxaddress__oxcountryid->value);

            $unzerAddressDelivery = (new \UnzerSDK\Resources\EmbeddedResources\Address())
                ->setName($oDeliveryAddress->oxaddress__oxfname->value . ' ' . $oDeliveryAddress->oxaddress__oxlname->value)
                ->setStreet($oDeliveryAddress->oxaddress__oxstreet->value . ' ' . $oDeliveryAddress->oxaddress__oxstreetnr->value)
                ->setZip($oDeliveryAddress->oxaddress__oxzip->value)
                ->setCity($oDeliveryAddress->oxaddress__oxcity->value)
                ->setCountry($oDeliveryCountry->oxcountry__oxisoalpha2->value);

            $deliveryCompanyName = $oDeliveryCountry->oxaddress__oxcompany->value;

            $unzerAddressDelivery->setShippingType(
                \UnzerSDK\Constants\ShippingTypes::DIFFERENT_ADDRESS
            );
        }

        $customerId = Constants::USER_ID_PREFIX . $oUser->getId();

        $need_customer_update = false;
        try {
            $unzerCustomer = UnzerpaymentClient::getInstance()->fetchCustomer($customerId);
            $need_customer_update = true;
        } catch (\Exception $e) {
            $unzerCustomer = new \UnzerSDK\Resources\Customer();
        }

        $lang = Registry::getLang();
        $iLang = $lang->getBaseLanguage();
        $sLang = $lang->getLanguageAbbr($iLang);

        $unzerCustomer
            ->setCustomerId($customerId)
            ->setFirstname($oUser->oxuser__oxfname->value)
            ->setLastname($oUser->oxuser__oxlname->value)
            ->setCompany($oUser->oxuser__oxcompany->value)
            ->setEmail($oUser->oxuser__oxusername->value)
            ->setMobile($oUser->oxuser__oxmobfon->value)
            ->setPhone($oUser->oxuser__oxfon->value)
            ->setLanguage($sLang)
            ->setBillingAddress($unzerAddressBilling)
            ->setShippingAddress($unzerAddressDelivery);
            
        if ($oUser->oxuser__oxcompany->value != '' || $deliveryCompanyName != '') {
            $unzerCompanyInfo = new \UnzerSDK\Resources\EmbeddedResources\CompanyInfo();
            $unzerCompanyInfo->setCompanyType('Company Type');
            $unzerCompanyInfo->setRegistrationType(CompanyRegistrationTypes::REGISTRATION_TYPE_NOT_REGISTERED);
            $unzerCompanyInfo->setFunction('OWNER');
            $unzerCompanyInfo->setCommercialSector(CompanyCommercialSectorItems::OTHER);
            $unzerCustomer->setCompanyInfo(
                $unzerCompanyInfo
            );
        } else {
            $unzerCustomer->setCompanyInfo(
                null
            );
        }            

        $birthdate = '0000-00-00';
        if ($oUser->oxuser__oxbirthdate && is_array($oUser->oxuser__oxbirthdate->value)) {
            $birthdate = new \OxidEsales\Eshop\Core\Field(
                $oUser->convertBirthday($oUser->oxuser__oxbirthdate->value),
                \OxidEsales\Eshop\Core\Field::T_RAW
            );
        } elseif ($oUser->oxuser__oxbirthdate && !is_array($oUser->oxuser__oxbirthdate->value)) {
            $birthdate = $oUser->oxuser__oxbirthdate->value;
        }

        if (UnzerpaymentHelper::getInstance()->isBirthDate($birthdate) && $birthdate != '0000-00-00') {
            $unzerCustomer
                ->setBirthDate((new \DateTime($birthdate))->format('Y-m-d'));
        }
        if ($oUser->oxuser__oxsal->value == 'MR') {
            $unzerCustomer
                ->setSalutation(\UnzerSDK\Constants\Salutations::MR);
        } elseif ($oUser->oxuser__oxsal->value == 'MRS') {
            $unzerCustomer
                ->setSalutation(\UnzerSDK\Constants\Salutations::MRS);
        } else {
            $unzerCustomer
                ->setSalutation(\UnzerSDK\Constants\Salutations::UNKNOWN);
        }

        if ($need_customer_update) {
            UnzerpaymentClient::getInstance()->updateCustomer($unzerCustomer);
        } else {
            UnzerpaymentClient::getInstance()->createCustomer($unzerCustomer);
        }

        $orderId = Constants::ORDER_ID_PREFIX . uniqid();

        $oBasket = $this->getBasket();

        $totalValueGross = $oBasket->getBruttoSum() - $oBasket->getTotalDiscountSum() + $oBasket->getDeliveryCost()->getBruttoPrice();

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
        
        UnzerpaymentClient::getInstance()->createBasket($basket);

        $oModule = oxNew('oxModule');
        $oModule->load(Constants::MODULE_ID);

        $metadata = new \UnzerSDK\Resources\Metadata();
        $metadata->setShopType('Oxid eShop ' . (new Facts())->getEdition());
        $metadata->setShopVersion(ShopVersion::getVersion());
        $metadata->addMetadata('pluginType', 'unzerdev/oxid7');
        $metadata->addMetadata('pluginVersion', $oModule->getInfo('version'));

        UnzerpaymentClient::getInstance()->createMetadata($metadata);

        $resources = new \UnzerSDK\Resources\EmbeddedResources\Paypage\Resources(
            $unzerCustomer->getId(),
            $basket->getId(),
            $metadata->getId()
        );

        $unzerPaymentMethodModel = $this->getPayment()->getUnzerPaymentMethodModel();

        $paymentMethodClass = UnzerpaymentClient::guessPaymentMethodClass($unzerPaymentMethodModel::UNZER_LONG_CODE);
        $currentMethodConfig = new \UnzerSDK\Resources\EmbeddedResources\Paypage\PaymentMethodConfig(true, 1);
        $paymentMethodsConfig = (new \UnzerSDK\Resources\EmbeddedResources\Paypage\PaymentMethodsConfigs())
            ->setDefault((new \UnzerSDK\Resources\EmbeddedResources\Paypage\PaymentMethodConfig())->setEnabled(false))
            ->addMethodConfig(
                $paymentMethodClass,
                $currentMethodConfig
            );

        if ($oUser->hasAccount() && in_array($paymentMethodClass, ['card', 'sepaDirectDebit', 'paypal'])) {
            $currentMethodConfig->setCredentialOnFile(true);
            $paymentMethodsConfig->addMethodConfig(
                $paymentMethodClass,
                $currentMethodConfig
            );
        } elseif (!$oUser->hasAccount() && in_array($paymentMethodClass, ['card', 'sepaDirectDebit', 'paypal'])) {
            $currentMethodConfig->setCredentialOnFile(false);
            $paymentMethodsConfig->addMethodConfig(
                $paymentMethodClass,
                $currentMethodConfig
            );
        }

        if ($paymentMethodClass == 'card') {
            if ($moduleSettingService->getBoolean('UnzerPaymentClickToPay', Constants::MODULE_ID)) {
                $paymentMethodsConfig->addMethodConfig(
                    'clicktopay',
                    new \UnzerSDK\Resources\EmbeddedResources\Paypage\PaymentMethodConfig(true, 2)
                );
            }
        }

        $risk = new \UnzerSDK\Resources\EmbeddedResources\RiskData();
        $risk->setRegistrationLevel($oUser->hasAccount() ? '1' : '0');
        if ($oUser->hasAccount()) {
            $oxregister = $oUser->getFieldData('oxregister');
            if ($oxregister == '0000-00-00 00:00:00') {
                $oxregister = gmdate('Y-m-d H:i:s');
            }
            $dtRegister = new \DateTime($oxregister);
            $registrationDate = $dtRegister->format('Ymd');
            $risk->setRegistrationDate(
                $registrationDate
            );

            $orderedAmount = 0.;
            $orderList = $oUser->getOrders();
            foreach ($orderList as $order) {
                $orderedAmount += $order->getTotalOrderSum();
            }

            $risk->setConfirmedAmount(UnzerpaymentHelper::getInstance()->prepareAmountValue($orderedAmount))
                ->setConfirmedOrders($oUser->getOrderCount());

            if ($oUser->getOrderCount() > 3) {
                $risk->setCustomerGroup('TOP');
            } elseif ($oUser->getOrderCount() >= 1) {
                $risk->setCustomerGroup('GOOD');
            } else {
                $risk->setCustomerGroup('NEUTRAL');
            }
        }

        $paypage = new \UnzerSDK\Resources\V2\Paypage($totalValueGross, $oBasket->getBasketCurrency()->name);
        $paypage->setPaymentMethodsConfigs($paymentMethodsConfig);
        $paypage->setResources($resources);
        $paypage->setType("embedded");
        $paypage->setCheckoutType(PaypageCheckoutTypes::PAYMENT_ONLY);
        $paypage->setOrderId($orderId);

        $paypageMode = UnzerpaymentHelper::getInstance()->getPaymentMethodChargeMode($selectedPaymentMethod);
        if ($paypageMode == '') {
            $paypageMode = $moduleSettingService->getString('UnzerPaymentMode', Constants::MODULE_ID) == '0' ?
                'authorize' : 'charge';
        }
        $paypage->setMode((string)$paypageMode);

        $redirectUrl = UnzerpaymentHelper::getInstance()->getRedirectUrl($this->getDeliveryAddressMD5());

        $paypage->setUrls(
            (new \UnzerSDK\Resources\EmbeddedResources\Paypage\Urls())
                ->setReturnSuccess($redirectUrl)
                ->setReturnFailure($redirectUrl)
                ->setReturnPending($redirectUrl)
                ->setReturnCancel($redirectUrl)
        );

        try {
            UnzerpaymentClient::getInstance()->createPaypage($paypage);
        } catch (\Exception $exception) {
            $this->logger->addLog('createPaypage Error', 1, $exception, [
                'paypage' => $paypage,
                'unzerCustomer' => $unzerCustomer,
                'basket' => $basket,
                'metadata' => $metadata
            ]);
        }

        $this->logger->addLog('received page page token', 3, false, [
            'UnzerPaymentId' => $paypage->getId(),
            'token' => $paypage->getId(),
        ]);

        $session = Registry::getSession();
        $session->setVariable('UnzerPaypageId', $paypage->getId());
        $session->setVariable('UnzerMetadataId', $metadata->getId());
        $session->setVariable('UnzerSelectedPaymentMethod', $selectedPaymentMethod);
        $this->addTplParam('unzerPaypageToken', $paypage->getId());
        $this->addTplParam('unzerRedirectUrl', $redirectUrl);
        $this->addTplParam('unzerErrorRedirectUrl', Registry::getConfig()->getCurrentShopUrl().'index.php?cl=payment&payerror=unzer');
        $this->addTplParam('unzerClickToPay', $moduleSettingService->getBoolean('UnzerPaymentClickToPay', Constants::MODULE_ID) ? '' : 'disableCTP');
    }

}