# Release Notes for Unzer Payment Plugin for OXID eShop 7

## [1.1.4]

- Added Unzer Order ID to enable cross platform search

## [1.1.3]

- Fixed transaction type for Wero

## [1.1.2]

- Optimization saving settings in backend

## [1.1.1]

- iDEAL Payment Logo Update

## [1.1.0]

- Added Wero payment method
- Updated payment logos to new design
- Fixed display incorrect payment state on orders
- Fixed a lot of “behind the scenes” issues to improve functionality

## [1.0.2]

- Compatibility OXID 7.3.0 / PHP8.4
- Update Unzer PHP-SDK
- Cancel-logic update for UPL methods

## [1.0.1]
- Payment-ID and Short-ID output on "thank you"-page in sandbox mode
- Enhanced backend transaction view
- Module configuration "click to pay" optimized
- Bugfix country validity check 

## [1.0.0]

We are excited to announce the release of the new Unzer Payment plugin for OXID eShop 7.

### 🔑 Key Features:

- **Integration with PayPage V2:** The plugin is integrated with Unzer’s PayPage V2 for an enhanced payment experience.
- **Plugin Implementation:** This version has been implemented as a standalone plugin, providing more flexibility and ease of maintenance, rather than being an integrated component.
- **Centralized Keypair Configuration:** The plugin supports configuration with a single master keypair, simplifying the setup. All payment methods, including Unzer PayLater, can be managed through this single keypair.

### 🔁 Migration Instructions:

1. **Obtain New Master Keypair:** Request a new master keypair that encompasses all desired payment methods.  
   **Install and Configure New Plugin:** Install the new Unzer Payment plugin and configure it using the new master keypair.

2. **Deactivate Payment Methods:** Disable all payment methods associated with the old plugin in the backend to prevent them from appearing in the checkout.

3. **Maintain Configuration of Old Plugin:** Keep the old plugin active with its current configuration. This will allow you to view, refund, and cancel any previous transactions.

4. **Activate New Payment Methods:** Once configured, activate the payment methods through the new plugin so they are visible during checkout.

5. **Support**  
   For any issues or further assistance with the new plugin, please contact our support team at: [support@unzer.com](mailto:support@unzer.com)

> By following these steps, you will be able to transition seamlessly to the new plugin while retaining the ability to manage existing transactions.

We hope you enjoy the enhancements brought by the new Unzer Payment plugin!

### 💳 Supported Payment Methods:

- AliPay
- Apple Pay
- Bancontact
- Cards and Click to Pay (CTP)
- Direct Bank Transfer
- Direct Debit Secured
- EPS
- Google Pay
- iDEAL
- Klarna
- PayPal
- PayU
- Post Finance eFinance
- Prepayment
- Przelewy24
- SEPA Direct Debit
- TWINT
- Unzer Installment
- Unzer Invoice
- Wero
- WeChat Pay

