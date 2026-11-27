<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Service;

use UnzerSDK\Resources\Customer;
use UnzerSDK\Resources\Payment;

final class ExpressCustomerDataNormalizer
{
    public const ADDRESS_PART_PLACEHOLDER = '-----';


    public function fromGooglePay(
        array $paymentData
    ): ?array {
        $billingGoogleAddress =
            $paymentData['paymentMethodData']['info']['billingAddress']
            ?? [];

        $name =
            $billingGoogleAddress['name']
            ?? self::ADDRESS_PART_PLACEHOLDER
        . ' '
        . self::ADDRESS_PART_PLACEHOLDER;

        $names = $this->separateName(
            $name
        );

        $shippingAddress = $this->getAddressFromGoogleData(
            $paymentData['shippingAddress'] ?? []
        );

        $billingAddress = $this->getAddressFromGoogleData(
            $billingGoogleAddress
        );

        if (
            !$this->isAddressDataBagComplete(
                $billingAddress
            )
        ) {
            $billingAddress = $shippingAddress;
        }

        return [
            'firstName' => $names['firstName'],
            'lastName' => $names['lastName'],
            'guest' => true,
            'email' => $paymentData['email'] ?? '',
            'acceptedDataProtection' => true,
            'billingAddress' => $billingAddress,
            'shippingAddress' => $shippingAddress,
        ];
    }


    public function fromApplePay(
        array $shippingContact,
        array $billingContact
    ): ?array {
        $firstName = $this->getRequiredAddressValue(
            $shippingContact['givenName'] ?? null
        );

        $lastName = $this->getRequiredAddressValue(
            $shippingContact['familyName'] ?? null
        );

        $shippingAddress = $this->getAddressFromApplePayData(
            $shippingContact
        );

        $billingAddress = $this->getAddressFromApplePayData(
            $billingContact
        );

        if (
            !$this->isAddressDataBagComplete(
                $billingAddress
            )
        ) {
            $billingAddress = $shippingAddress;
        }

        return [
            'firstName' => $firstName,
            'lastName' => $lastName,
            'guest' => true,
            'email' => $shippingContact['emailAddress'] ?? '',
            'acceptedDataProtection' => true,
            'billingAddress' => $billingAddress,
            'shippingAddress' => $shippingAddress,
        ];
    }


    public function fromUnzerPayment(
        Payment $payment
    ): ?array {
        $customer = $payment->getCustomer();

        if ($customer === null) {
            return null;
        }

        $shippingAddress = $this->getAddressFromUnzerCustomer(
            $customer,
            'shipping'
        );

        $billingAddress = $this->getAddressFromUnzerCustomer(
            $customer,
            'billing'
        );

        if (
            !$this->isAddressDataBagComplete(
                $billingAddress
            )
        ) {
            $billingAddress = $shippingAddress;
        }

        return [
            'firstName' => $customer->getFirstname(),
            'lastName' => $customer->getLastname(),
            'guest' => true,
            'email' => $customer->getEmail(),
            'acceptedDataProtection' => true,
            'billingAddress' => $billingAddress,
            'shippingAddress' => $shippingAddress,
        ];
    }


    private function isAddressDataBagComplete(
        array $dataBag
    ): bool {
        $requiredFields = [
            'firstName',
            'lastName',
            'street',
            'zipcode',
            'city',
            'country',
        ];

        foreach ($requiredFields as $field) {
            $value = trim(
                (string) ($dataBag[$field] ?? '')
            );

            if (
                $value === ''
                || $value === self::ADDRESS_PART_PLACEHOLDER
            ) {
                return false;
            }
        }

        return true;
    }


    private function getAddressFromGoogleData(
        array $address
    ): array {
        $name = $address['name']
            ?? self::ADDRESS_PART_PLACEHOLDER
            . ' '
            . self::ADDRESS_PART_PLACEHOLDER;

        $nameParts = $this->separateName(
            $name
        );

        return [
            'firstName' => $nameParts['firstName'],
            'lastName' => $nameParts['lastName'],

            'city' => $this->getRequiredAddressValue(
                $address['locality'] ?? null
            ),

            'country' => $this->getRequiredAddressValue(
                $address['countryCode'] ?? null
            ),

            'street' => $this->getRequiredAddressValue(
                $address['address1'] ?? null
            ),

            'additionalAddressLine1' =>
                $address['address2'] ?? null,

            'additionalAddressLine2' =>
                $address['address3'] ?? null,

            'zipcode' => $this->getRequiredAddressValue(
                $address['postalCode'] ?? null
            ),

            'phoneNumber' =>
                $address['phoneNumber'] ?? '',

            'company' =>
                $address['organizationName'] ?? '',
        ];
    }


    private function getAddressFromApplePayData(
        array $address
    ): array {
        return [
            'firstName' => $this->getRequiredAddressValue(
                $address['givenName'] ?? null
            ),

            'lastName' => $this->getRequiredAddressValue(
                $address['familyName'] ?? null
            ),

            'city' => $this->getRequiredAddressValue(
                $address['locality'] ?? null
            ),

            'country' => $this->getRequiredAddressValue(
                $address['countryCode'] ?? null
            ),

            'street' => $this->getRequiredAddressValue(
                $address['addressLines'][0] ?? null
            ),

            'additionalAddressLine1' =>
                $address['addressLines'][1] ?? null,

            'additionalAddressLine2' =>
                $address['addressLines'][2] ?? null,

            'zipcode' => $this->getRequiredAddressValue(
                $address['postalCode'] ?? null
            ),

            'phoneNumber' =>
                $address['phoneNumber'] ?? '',

            'company' => '',
        ];
    }


    private function getAddressFromUnzerCustomer(
        Customer $customer,
        string $addressType = 'billing'
    ): array {
        $address = $addressType === 'billing'
            ? $customer->getBillingAddress()
            : $customer->getShippingAddress();

        if ($address === null) {
            return $this->getEmptyAddressData();
        }

        $name = $address->getName();

        if (empty($name)) {
            $name =
                self::ADDRESS_PART_PLACEHOLDER
                . ' '
                . self::ADDRESS_PART_PLACEHOLDER;
        }

        $nameParts = $this->separateName(
            $name
        );

        return [
            'firstName' => $nameParts['firstName'],
            'lastName' => $nameParts['lastName'],

            'city' => $this->getRequiredAddressValue(
                $address->getCity()
            ),

            'country' => $this->getRequiredAddressValue(
                $address->getCountry()
            ),

            'street' => $this->getRequiredAddressValue(
                $address->getStreet()
            ),

            'additionalAddressLine1' => null,
            'additionalAddressLine2' => null,

            'zipcode' => $this->getRequiredAddressValue(
                $address->getZip()
            ),

            'phoneNumber' => '',
            'company' => '',
        ];
    }


    private function getEmptyAddressData(): array
    {
        return [
            'firstName' => self::ADDRESS_PART_PLACEHOLDER,
            'lastName' => self::ADDRESS_PART_PLACEHOLDER,
            'city' => self::ADDRESS_PART_PLACEHOLDER,
            'country' => self::ADDRESS_PART_PLACEHOLDER,
            'street' => self::ADDRESS_PART_PLACEHOLDER,
            'additionalAddressLine1' => null,
            'additionalAddressLine2' => null,
            'zipcode' => self::ADDRESS_PART_PLACEHOLDER,
            'phoneNumber' => '',
            'company' => '',
        ];
    }


    private function getRequiredAddressValue(
        mixed $value
    ): string {
        $value = trim(
            (string) $value
        );

        return $value !== ''
            ? $value
            : self::ADDRESS_PART_PLACEHOLDER;
    }


    private function separateName(
        string $name
    ): array {
        $name = trim($name);

        if ($name === '') {
            return [
                'firstName' => self::ADDRESS_PART_PLACEHOLDER,
                'lastName' => self::ADDRESS_PART_PLACEHOLDER,
            ];
        }

        $exploded = preg_split(
            '/\s+/u',
            $name
        );

        if (count($exploded) > 1) {
            $lastName = array_pop(
                $exploded
            );

            return [
                'firstName' => implode(
                    ' ',
                    $exploded
                ),
                'lastName' => $lastName,
            ];
        }

        return [
            'firstName' => $exploded[0],
            'lastName' => '.',
        ];
    }
}
