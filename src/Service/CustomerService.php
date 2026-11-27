<?php

namespace Unzer\UnzerPayment\Service;

use Exception;
use OxidEsales\Eshop\Application\Model\Country;
use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\Registry;
use Unzer\UnzerPayment\Classes\UnzerpaymentClient;
use Unzer\UnzerPayment\Constants\Constants;
use UnzerSDK\Constants\ShippingTypes;
use UnzerSDK\Resources\Customer;
use UnzerSDK\Resources\EmbeddedResources\Address;
use UnzerSDK\Resources\EmbeddedResources\CompanyInfo;

class CustomerService
{
    public const SESSION_KEY_USER_ID = 'unzer_temp_user_id';

    public function __construct()
    {
    }

    public function calculateCustomerNumber(?string $customerId = null): string
    {
        $config = Registry::getConfig();
        $session = Registry::getSession();

        $shopId = (string) $config->getShopId();

        $prefix = Constants::USER_ID_PREFIX
            . $shopId
            . '-'
            . substr(
                md5(
                    html_entity_decode(
                        $config->getShopUrl(),
                        ENT_QUOTES | ENT_HTML5
                    )
                ),
                0,
                10
            )
            . '-';

        if ($customerId !== null && $customerId !== '') {
            return $prefix . $customerId;
        }

        /** @var User|null $user */
        $user = $session->getUser();

        if ($user && $user->getId()) {
            return $prefix . $user->getId();
        }

        $sessionUserId = $session->getVariable(
            self::SESSION_KEY_USER_ID
        );

        if (empty($sessionUserId)) {
            $sessionUserId = uniqid('', true);

            $session->setVariable(
                self::SESSION_KEY_USER_ID,
                $sessionUserId
            );
        }

        return 'temp-' . $prefix . $sessionUserId;
    }

    public function getCustomerFromData(
        $paymentGateway,
        array $data
    ): ?Customer {

        $paymentService = new PaymentService();

        $company = $this->getDataValue(
            $data,
            [
                'company',
                'billing_company',
                'oxuser__oxcompany',
            ],
            ''
        );

        $currency = Registry::getConfig()
            ->getActShopCurrencyObject()
            ->name;

        $unzer = UnzerpaymentClient::getInstance();

        $customerNumber = $this->calculateCustomerNumber();

        $customer = null;

        try {
            $customer = $unzer->fetchCustomerByExtCustomerId(
                $customerNumber
            );
        } catch (Exception $exception) {
            // Customer does not exist yet.
        }

        if (!$customer) {
            $customer = new Customer();

            $customer->setCustomerId(
                $customerNumber
            );
        }

        $customer->setLanguage(
            $this->getCurrentLanguage()
        );

        $firstName = $this->getDataValue(
            $data,
            [
                'firstName',
                'billing_first_name',
                'oxuser__oxfname',
            ],
            ''
        );

        $lastName = $this->getDataValue(
            $data,
            [
                'lastName',
                'billing_last_name',
                'oxuser__oxlname',
            ],
            ''
        );

        $email = $this->getDataValue(
            $data,
            [
                'email',
                'billing_email',
                'oxuser__oxusername',
            ],
            ''
        );

        $phone = $this->getDataValue(
            $data,
            [
                'phone',
                'billing_phone',
                'oxuser__oxfon',
            ],
            ''
        );

        if ($firstName !== '' && $lastName !== '') {
            $customer
                ->setFirstname($firstName)
                ->setLastname($lastName);
        }

        if ($email !== '') {
            $customer->setEmail(
                $email
            );
        }

        if ($phone !== '') {
            $customer->setPhone(
                $phone
            );
        }

        $this->applyCompanyData(
            $customer,
            $company
        );

        $billingAddress = $customer->getBillingAddress();

        if (!$billingAddress) {
            $billingAddress = new Address();
        }

        if ($firstName !== '' || $lastName !== '') {
            $billingAddress->setName(
                trim($firstName . ' ' . $lastName)
            );
        }

        $street = $this->getDataValue(
            $data,
            [
                'street',
                'billing_address_1',
                'oxuser__oxstreet',
            ],
            ''
        );

        $streetNumber = $this->getDataValue(
            $data,
            [
                'streetNumber',
                'oxuser__oxstreetnr',
            ],
            ''
        );

        if ($street !== '') {
            $billingAddress->setStreet(
                trim(
                    $street
                    . ' '
                    . $streetNumber
                )
            );
        }

        $city = $this->getDataValue(
            $data,
            [
                'city',
                'billing_city',
                'oxuser__oxcity',
            ],
            ''
        );

        if ($city !== '') {
            $billingAddress->setCity(
                $city
            );
        }

        $zip = $this->getDataValue(
            $data,
            [
                'zipcode',
                'billing_postcode',
                'oxuser__oxzip',
            ],
            ''
        );

        if ($zip !== '') {
            $billingAddress->setZip(
                $zip
            );
        }

        $country = $this->getDataValue(
            $data,
            [
                'country',
                'billing_country',
            ],
            ''
        );

        /*
         * OXID forms often contain the internal OXCOUNTRY ID instead of
         * ISO-2. Support that as well.
         */
        if ($country === '') {
            $countryId = $this->getDataValue(
                $data,
                [
                    'countryId',
                    'oxuser__oxcountryid',
                ],
                ''
            );

            if ($countryId !== '') {
                $country = $this->getCountryIso2(
                    $countryId
                );
            }
        }

        if ($country !== '') {
            $billingAddress->setCountry(
                $country
            );
        }

        $customer->setBillingAddress(
            $billingAddress
        );


        if (
            empty($customer->getFirstname())
            || empty($customer->getLastname())
        ) {
            return null;
        }

        if (empty($customer->getId())) {
            try {
                $customer = $unzer->createCustomer(
                    $customer
                );
            } catch (Exception $exception) {
                return null;
            }
        } else {
            try {
                $customer = $unzer->updateCustomer(
                    $customer
                );
            } catch (Exception $exception) {
                Registry::getLogger()->warning('Could not update Unzer customer', [
                    'customerId' => $customer->getCustomerId(),
                    'exception' => $exception,
                ]);
            }
        }

        return $customer->getId()
            ? $customer
            : null;
    }

    public function getCustomerFromSession(
        $paymentGateway,
        ?string $orderId = null
    ): ?Customer {
        if (!empty($orderId)) {
            return $this->getCustomerFromOrder(
                $orderId
            );
        }

        /** @var User|null $user */
        $user = Registry::getSession()->getUser();

        if (!$user) {
            return null;
        }

        $data = [
            'oxuser__oxfname' =>
                $user->oxuser__oxfname->value ?? '',

            'oxuser__oxlname' =>
                $user->oxuser__oxlname->value ?? '',

            'oxuser__oxcompany' =>
                $user->oxuser__oxcompany->value ?? '',

            'oxuser__oxusername' =>
                $user->oxuser__oxusername->value ?? '',

            'oxuser__oxfon' =>
                $user->oxuser__oxfon->value ?? '',

            'oxuser__oxstreet' =>
                $user->oxuser__oxstreet->value ?? '',

            'oxuser__oxstreetnr' =>
                $user->oxuser__oxstreetnr->value ?? '',

            'oxuser__oxzip' =>
                $user->oxuser__oxzip->value ?? '',

            'oxuser__oxcity' =>
                $user->oxuser__oxcity->value ?? '',

            'oxuser__oxcountryid' =>
                $user->oxuser__oxcountryid->value ?? '',
        ];

        return $this->getCustomerFromData(
            $paymentGateway,
            $data
        );
    }

    /**
     * @param string|Order $order
     */
    public function getCustomerFromOrder(string|Order $order): Customer
    {
        $order = $this->getOrder($order);
        $unzer = UnzerpaymentClient::getInstance();
        $userId = (string) ($order->oxorder__oxuserid->value ?? '');
        $customerNumber = $this->calculateCustomerNumber($userId ?: null);

        try {
            $customer = $unzer->fetchCustomerByExtCustomerId($customerNumber);
        } catch (Exception $exception) {
            $customer = null;
        }

        if (!$customer) {
            $customer = (new Customer())->setCustomerId($customerNumber);
        }

        $this->applyOrderDataToCustomer($customer, $order);
        return $this->persistCustomer($customer, $unzer);
    }

    /**
     * Used for PayPal Express where the Unzer customer already exists
     * before the OXID order is finalized.
     *
     * @param string|Order $order
     */
    public function updatePaypalExpressCustomer(string|Order $order, Customer $customer): Customer
    {
        $order = $this->getOrder($order);
        $this->applyOrderDataToCustomer($customer, $order);
        return $this->persistCustomer($customer, UnzerpaymentClient::getInstance());
    }
    private function applyOrderDataToCustomer(Customer $customer, Order $order): void
    {
        $company = (string) ($order->oxorder__oxbillcompany->value ?? '');

        $customer
            ->setLanguage($this->getCurrentLanguage())
            ->setFirstname((string) ($order->oxorder__oxbillfname->value ?? ''))
            ->setLastname((string) ($order->oxorder__oxbilllname->value ?? ''))
            ->setPhone((string) ($order->oxorder__oxbillfon->value ?? ''))
            ->setCompany($company)
            ->setEmail((string) ($order->oxorder__oxbillemail->value ?? ''));

        $this->applyCompanyData($customer, $company);
        $this->setAddresses($customer, $order);
    }

    private function persistCustomer(Customer $customer, $unzer): Customer
    {
        try {
            return $customer->getId()
                ? $unzer->updateCustomer($customer)
                : $unzer->createCustomer($customer);
        } catch (Exception $exception) {
            Registry::getLogger()->warning('Could not persist Unzer customer', [
                'customerId' => $customer->getCustomerId(),
                'exception' => $exception,
            ]);

            return $customer;
        }
    }


    protected function setAddresses(
        Customer $customer,
        Order $order
    ): void {
        $billingAddress = (new Address())
            ->setName(
                trim(
                    (string) (
                        $order->oxorder__oxbillfname->value
                        ?? ''
                    )
                    . ' '
                    . (string) (
                        $order->oxorder__oxbilllname->value
                        ?? ''
                    )
                )
            )
            ->setStreet(
                $this->combineStreet(
                    (string) (
                        $order->oxorder__oxbillstreet->value
                        ?? ''
                    ),
                    (string) (
                        $order->oxorder__oxbillstreetnr->value
                        ?? ''
                    )
                )
            )
            ->setZip(
                (string) (
                    $order->oxorder__oxbillzip->value
                    ?? ''
                )
            )
            ->setCity(
                (string) (
                    $order->oxorder__oxbillcity->value
                    ?? ''
                )
            )
            ->setCountry(
                $this->getCountryIso2(
                    (string) (
                        $order->oxorder__oxbillcountryid->value
                        ?? ''
                    )
                )
            );

        $hasShippingAddress = $this->orderHasShippingAddress(
            $order
        );

        if ($hasShippingAddress) {
            $shippingAddress = (new Address())
                ->setName(
                    trim(
                        (string) (
                            $order->oxorder__oxdelfname->value
                            ?? ''
                        )
                        . ' '
                        . (string) (
                            $order->oxorder__oxdellname->value
                            ?? ''
                        )
                    )
                )
                ->setStreet(
                    $this->combineStreet(
                        (string) (
                            $order->oxorder__oxdelstreet->value
                            ?? ''
                        ),
                        (string) (
                            $order->oxorder__oxdelstreetnr->value
                            ?? ''
                        )
                    )
                )
                ->setZip(
                    (string) (
                        $order->oxorder__oxdelzip->value
                        ?? ''
                    )
                )
                ->setCity(
                    (string) (
                        $order->oxorder__oxdelcity->value
                        ?? ''
                    )
                )
                ->setCountry(
                    $this->getCountryIso2(
                        (string) (
                            $order->oxorder__oxdelcountryid->value
                            ?? ''
                        )
                    )
                )
                ->setShippingType(
                    ShippingTypes::DIFFERENT_ADDRESS
                );
        } else {
            $shippingAddress = (new Address())
                ->setName(
                    $billingAddress->getName()
                )
                ->setStreet(
                    $billingAddress->getStreet()
                )
                ->setZip(
                    $billingAddress->getZip()
                )
                ->setCity(
                    $billingAddress->getCity()
                )
                ->setCountry(
                    $billingAddress->getCountry()
                )
                ->setShippingType(
                    ShippingTypes::EQUALS_BILLING
                );
        }

        $customer
            ->setShippingAddress(
                $shippingAddress
            )
            ->setBillingAddress(
                $billingAddress
            );
    }

    protected function orderHasShippingAddress(
        Order $order
    ): bool {
        return trim(
                (string) (
                    $order->oxorder__oxdellname->value
                    ?? ''
                )
            ) !== ''
            || trim(
                (string) (
                    $order->oxorder__oxdelstreet->value
                    ?? ''
                )
            ) !== '';
    }

    protected function applyCompanyData(
        Customer $customer,
        string $company
    ): void {
        $company = trim(
            $company
        );

        $updatedCompany = (
            (string) $customer->getCompany()
            !== $company
        );

        $customer->setCompany(
            $company
        );

        if ($company === '') {
            $customer->setCompanyInfo(
                null
            );

            return;
        }

        $companyInfo = $customer->getCompanyInfo();

        if (!$companyInfo) {
            $companyInfo = new CompanyInfo();
        }

        if (
            empty($companyInfo->getCompanyType())
            || $updatedCompany
        ) {
            $companyInfo->setCompanyType(
                'Company Type'
            );
        }

        $customer->setCompanyInfo(
            $companyInfo
        );
    }

    protected function getCountryIso2(
        string $countryId
    ): string {
        $countryId = trim(
            $countryId
        );

        if ($countryId === '') {
            return '';
        }

        /*
         * Already ISO-2?
         */
        if (
            strlen($countryId) === 2
            && ctype_alpha($countryId)
        ) {
            return strtoupper(
                $countryId
            );
        }

        /** @var Country $country */
        $country = oxNew(
            Country::class
        );

        if (!$country->load($countryId)) {
            return '';
        }

        return strtoupper(
            (string) (
                $country->oxcountry__oxisoalpha2->value
                ?? ''
            )
        );
    }

    protected function combineStreet(
        string $street,
        string $streetNumber
    ): string {
        return trim(
            $street
            . ' '
            . $streetNumber
        );
    }

    protected function getCurrentLanguage(): string
    {
        $language = Registry::getLang()
            ->getLanguageAbbr();

        return substr(
            $language ?: 'en',
            0,
            2
        );
    }

    /**
     * @param string|Order $order
     */
    protected function getOrder(
        string|Order $order
    ): Order {
        if ($order instanceof Order) {
            return $order;
        }

        /** @var Order $orderObject */
        $orderObject = oxNew(
            Order::class
        );

        if (!$orderObject->load($order)) {
            throw new \RuntimeException(
                'Could not load OXID order: '
                . $order
            );
        }

        return $orderObject;
    }

    protected function getDataValue(
        array $data,
        array $keys,
        mixed $default = null
    ): mixed {
        foreach ($keys as $key) {
            if (
                array_key_exists(
                    $key,
                    $data
                )
            ) {
                return $data[$key];
            }
        }

        return $default;
    }
}