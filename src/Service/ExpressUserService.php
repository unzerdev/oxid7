<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Service;

use OxidEsales\Eshop\Application\Model\Address;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Field;
use OxidEsales\Eshop\Core\Registry;

final class ExpressUserService
{
    private const GUEST_SUFFIX = '@express.invalid';

    public const ADDRESS_PART_PLACEHOLDER = '-----';
    private const SESSION_DELIVERY_ADDRESS_ID = 'deladrid';


    public function createGuest(array $data): User
    {
        /** @var User $user */
        $user = oxNew(User::class);
        $this->applyBillingAddressToUser($user, $data['billingAddress'] ?? [], $data);

        $alias = bin2hex(random_bytes(16)) . self::GUEST_SUFFIX;
        $user->oxuser__oxusername = new Field($alias, Field::T_RAW);
        $user->oxuser__oxpassword = new Field('', Field::T_RAW);
        $user->oxuser__oxactive = new Field(1, Field::T_RAW);
        $user->oxuser__oxrights = new Field('user', Field::T_RAW);
        $user->oxuser__oxshopid = new Field(Registry::getConfig()->getShopId(), Field::T_RAW);

        $id = bin2hex(random_bytes(16));
        $fields = [
            'oxusername', 'oxpassword', 'oxactive', 'oxrights', 'oxshopid',
            'oxfname', 'oxlname', 'oxcompany', 'oxstreet', 'oxstreetnr',
            'oxaddinfo', 'oxzip', 'oxcity', 'oxcountryid', 'oxfon',
        ];
        $values = [$id, date('Y-m-d H:i:s'), $user->getBoni()];
        foreach ($fields as $field) {
            $values[] = $user->getRawFieldData($field);
        }
        $columns = implode(', ', $fields);
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $db = DatabaseProvider::getMaster();
        $inserted = $db->execute(
            "INSERT INTO oxuser (oxid, oxcreate, oxboni, $columns) VALUES ($placeholders)",
            $values
        );
        if ($inserted !== 1 || !$user->load($id) || $user->getId() !== $id || !self::isExpressGuest($user)) {
            throw new \RuntimeException('Could not load newly created express checkout guest');
        }

        return $user;
    }

    public static function isExpressGuest(User $user): bool
    {
        return !$user->hasAccount()
            && preg_match('/^[a-f0-9]{32}@express\.invalid$/D', (string) $user->getFieldData('oxusername')) === 1;
    }

    private function applyBillingAddressToUser(
        User $user,
        array $address,
        array $customerData
    ): void {
        [$street, $streetNumber] = $this->separateStreet(
            (string) ($address['street'] ?? '')
        );

        $user->oxuser__oxfname = new Field(
            $address['firstName']
            ?? $customerData['firstName']
            ?? '',
            Field::T_RAW
        );

        $user->oxuser__oxlname = new Field(
            $address['lastName']
            ?? $customerData['lastName']
            ?? '',
            Field::T_RAW
        );

        $user->oxuser__oxcompany = new Field(
            $address['company'] ?? '',
            Field::T_RAW
        );

        $user->oxuser__oxstreet = new Field(
            $street,
            Field::T_RAW
        );

        $user->oxuser__oxstreetnr = new Field(
            $streetNumber,
            Field::T_RAW
        );

        $user->oxuser__oxaddinfo = new Field(
            $this->getAdditionalAddressInfo($address),
            Field::T_RAW
        );

        $user->oxuser__oxzip = new Field(
            $address['zipcode'] ?? '',
            Field::T_RAW
        );

        $user->oxuser__oxcity = new Field(
            $address['city'] ?? '',
            Field::T_RAW
        );

        $user->oxuser__oxcountryid = new Field(
            $this->getCountryId(
                (string) ($address['country'] ?? '')
            ),
            Field::T_RAW
        );

        $user->oxuser__oxfon = new Field(
            $address['phoneNumber'] ?? '',
            Field::T_RAW
        );
    }


    public function setShippingAddress(
        User $user,
        array $data
    ): void {
        $session = Registry::getSession();

        $billingAddress = $data['billingAddress'] ?? [];
        $shippingAddress = $data['shippingAddress'] ?? $billingAddress;

        if (!$this->addressesDiffer(
            $billingAddress,
            $shippingAddress
        )) {
            $session->deleteVariable(
                self::SESSION_DELIVERY_ADDRESS_ID
            );

            $user->setSelectedAddressId(null);

            return;
        }

        $addressId = $this->findMatchingShippingAddress(
            $user,
            $shippingAddress
        );

        if ($addressId === null) {
            $addressId = $this->createShippingAddress(
                $user,
                $shippingAddress
            );
        }

        $session->setVariable(
            self::SESSION_DELIVERY_ADDRESS_ID,
            $addressId
        );

        $user->setSelectedAddressId(
            $addressId
        );
    }


    private function findMatchingShippingAddress(
        User $user,
        array $addressData
    ): ?string {
        $addresses = $user->getUserAddresses();

        foreach ($addresses as $address) {
            if (!$address instanceof Address) {
                continue;
            }

            if ($this->shippingAddressMatches(
                $address,
                $addressData
            )) {
                return $address->getId();
            }
        }

        return null;
    }


    private function shippingAddressMatches(
        Address $address,
        array $data
    ): bool {
        [$street, $streetNumber] = $this->separateStreet(
            (string) ($data['street'] ?? '')
        );

        $countryId = $this->getCountryId(
            (string) ($data['country'] ?? '')
        );

        return
            $this->normalizeString($address->oxaddress__oxfname->value)
            === $this->normalizeString($data['firstName'] ?? '')
            &&
            $this->normalizeString($address->oxaddress__oxlname->value)
            === $this->normalizeString($data['lastName'] ?? '')
            &&
            $this->normalizeString($address->oxaddress__oxcompany->value)
            === $this->normalizeString($data['company'] ?? '')
            &&
            $this->normalizeString($address->oxaddress__oxstreet->value)
            === $this->normalizeString($street)
            &&
            $this->normalizeString($address->oxaddress__oxstreetnr->value)
            === $this->normalizeString($streetNumber)
            &&
            $this->normalizeString($address->oxaddress__oxaddinfo->value)
            === $this->normalizeString(
                $this->getAdditionalAddressInfo($data)
            )
            &&
            $this->normalizeString($address->oxaddress__oxzip->value)
            === $this->normalizeString($data['zipcode'] ?? '')
            &&
            $this->normalizeString($address->oxaddress__oxcity->value)
            === $this->normalizeString($data['city'] ?? '')
            &&
            (string) $address->oxaddress__oxcountryid->value
            === $countryId;
    }


    private function createShippingAddress(
        User $user,
        array $addressData
    ): string {
        /** @var Address $address */
        $address = oxNew(Address::class);

        [$street, $streetNumber] = $this->separateStreet(
            (string) ($addressData['street'] ?? '')
        );

        $address->oxaddress__oxuserid = new Field(
            $user->getId(),
            Field::T_RAW
        );

        $address->oxaddress__oxfname = new Field(
            $addressData['firstName'] ?? '',
            Field::T_RAW
        );

        $address->oxaddress__oxlname = new Field(
            $addressData['lastName'] ?? '',
            Field::T_RAW
        );

        $address->oxaddress__oxcompany = new Field(
            $addressData['company'] ?? '',
            Field::T_RAW
        );

        $address->oxaddress__oxstreet = new Field(
            $street,
            Field::T_RAW
        );

        $address->oxaddress__oxstreetnr = new Field(
            $streetNumber,
            Field::T_RAW
        );

        $address->oxaddress__oxaddinfo = new Field(
            $this->getAdditionalAddressInfo($addressData),
            Field::T_RAW
        );

        $address->oxaddress__oxzip = new Field(
            $addressData['zipcode'] ?? '',
            Field::T_RAW
        );

        $address->oxaddress__oxcity = new Field(
            $addressData['city'] ?? '',
            Field::T_RAW
        );

        $address->oxaddress__oxcountryid = new Field(
            $this->getCountryId(
                (string) ($addressData['country'] ?? '')
            ),
            Field::T_RAW
        );

        $address->oxaddress__oxfon = new Field(
            $addressData['phoneNumber'] ?? '',
            Field::T_RAW
        );

        if (!$address->save() || !$address->getId()) {
            throw new \RuntimeException(
                'Could not create OXID shipping address for express checkout'
            );
        }

        return $address->getId();
    }


    private function getCountryId(
        string $countryCode
    ): string {
        $countryCode = strtoupper(
            trim($countryCode)
        );

        if ($countryCode === '') {
            return '';
        }
        /** @var User $user */
        $user = oxNew(User::class);

        return (string) $user->getUserCountryId(
            $countryCode
        );
    }


    private function addressesDiffer(
        array $billing,
        array $shipping
    ): bool {
        $fields = [
            'firstName',
            'lastName',
            'company',
            'street',
            'additionalAddressLine1',
            'additionalAddressLine2',
            'zipcode',
            'city',
            'country',
            'phoneNumber',
        ];

        foreach ($fields as $field) {
            if (
                $this->normalizeString(
                    $billing[$field] ?? ''
                )
                !==
                $this->normalizeString(
                    $shipping[$field] ?? ''
                )
            ) {
                return true;
            }
        }

        return false;
    }


    private function getAdditionalAddressInfo(
        array $address
    ): string {
        $lines = [];

        if (!empty($address['additionalAddressLine1'])) {
            $lines[] = trim(
                (string) $address['additionalAddressLine1']
            );
        }

        if (!empty($address['additionalAddressLine2'])) {
            $lines[] = trim(
                (string) $address['additionalAddressLine2']
            );
        }

        return implode(
            ', ',
            $lines
        );
    }


    private function separateStreet(
        string $street
    ): array {
        $street = trim($street);

        if ($street === '') {
            return [
                self::ADDRESS_PART_PLACEHOLDER,
                '',
            ];
        }

        /*
         * Typical DE/AT/CH:
         *
         * Musterstraße 12
         * Musterstraße 12a
         * Muster Straße 12 b
         * Musterstraße 12/1
         * Musterstraße 12-14
         *
         * For addresses not matching this format we keep the complete
         * value in OXSTREET and leave OXSTREETNR empty.
         */
        if (
            preg_match(
                '/^(.+?)\s+(\d+[a-zA-Z]?(?:[\s\/\-]\w+)*)$/u',
                $street,
                $matches
            )
        ) {
            return [
                trim($matches[1]),
                trim($matches[2]),
            ];
        }

        return [
            $street,
            '',
        ];
    }


    private function normalizeString(
        mixed $value
    ): string {
        return trim(
            preg_replace(
                '/\s+/u',
                ' ',
                (string) $value
            )
        );
    }
}
