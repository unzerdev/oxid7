<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Constants;

class Constants
{

    const PAYMENT_METHOD_PREFIX = 'up_';
    const MODULE_ID = 'unzer_payment';
    const USER_ID_PREFIX = 'OX-';
    const ORDER_ID_PREFIX = 'OXPREOID-';
    const PAYMENT_METHODS = [
        'alipay' => [
            'status' => 1,
            'short_code' => 'ali',
            'long_code' => 'alipay',
            'payment_method_code' => 'UNZER_ALIPAY',
            'name' => 'Unzer Alipay',
            'class_name' => 'UnzerAlipayPaymentMethod',
        ],
        'applepay' => [
            'status' => 1,
            'short_code' => 'apl',
            'long_code' => 'applepay',
            'payment_method_code' => 'UNZER_APPLEPAY',
            'name' => 'Unzer Apple Pay',
            'class_name' => 'UnzerApplepayPaymentMethod',
        ],
        'bancontact' => [
            'status' => 1,
            'short_code' => 'bct',
            'long_code' => 'bancontact',
            'payment_method_code' => 'UNZER_BANCONTACT',
            'name' => 'Unzer Bancontact',
            'class_name' => 'UnzerBancontactPaymentMethod',
        ],
        'card' => [
            'status' => 1,
            'short_code' => 'crd',
            'long_code' => 'card',
            'payment_method_code' => 'UNZER_CARD',
            'name' => 'Unzer Credit Card',
            'class_name' => 'UnzerCardPaymentMethod',
        ],
        'eps' => [
            'status' => 1,
            'short_code' => 'eps',
            'long_code' => 'eps',
            'payment_method_code' => 'UNZER_EPS',
            'name' => 'Unzer EPS',
            'class_name' => 'UnzerEpsPaymentMethod',
        ],
        'googlepay' => [
            'status' => 1,
            'short_code' => 'gop',
            'long_code' => 'googlepay',
            'payment_method_code' => 'UNZER_GOOGLEPAY',
            'name' => 'Unzer Google Pay',
            'class_name' => 'UnzerGooglepayPaymentMethod',
        ],
        'ideal' => [
            'status' => 1,
            'short_code' => 'idl',
            'long_code' => 'ideal',
            'payment_method_code' => 'UNZER_IDEAL',
            'name' => 'Unzer iDEAL',
            'class_name' => 'UnzerIdealPaymentMethod',
        ],
        'klarna' => [
            'status' => 1,
            'short_code' => 'kla',
            'long_code' => 'klarna',
            'payment_method_code' => 'UNZER_KLARNA',
            'name' => 'Unzer Klarna',
            'class_name' => 'UnzerKlarnaPaymentMethod',
        ],
        'openbanking_pis' => [
            'status' => 1,
            'short_code' => 'obp',
            'long_code' => 'openbanking_pis',
            'payment_method_code' => 'UNZER_OPENBANKING_PIS',
            'name' => 'Unzer Open Banking',
            'class_name' => 'UnzerOpenbankingPisPaymentMethod',
        ],
        'paypal' => [
            'status' => 1,
            'short_code' => 'ppl',
            'long_code' => 'paypal',
            'payment_method_code' => 'UNZER_PAYPAL',
            'name' => 'Unzer PayPal',
            'class_name' => 'UnzerPaypalPaymentMethod',
        ],
        'payu' => [
            'status' => 1,
            'short_code' => 'pyu',
            'long_code' => 'payu',
            'payment_method_code' => 'UNZER_PAYU',
            'name' => 'Unzer PayU',
            'class_name' => 'UnzerPayuPaymentMethod',
        ],
        'paylater_direct_debit' => [
            'status' => 1,
            'short_code' => 'pdd',
            'long_code' => 'paylater_direct_debit',
            'payment_method_code' => 'UNZER_PAYLATER_DIRECT_DEBIT',
            'name' => 'Unzer Paylater Direct Debit',
            'class_name' => 'UnzerPaylaterDirectDebitPaymentMethod',
        ],
        'paylater_invoice' => [
            'status' => 1,
            'short_code' => 'piv',
            'long_code' => 'paylater_invoice',
            'payment_method_code' => 'UNZER_PAYLATER_INVOICE',
            'name' => 'Unzer Paylater Invoice',
            'class_name' => 'UnzerPaylaterInvoicePaymentMethod',
        ],
        'paylater_installment' => [
            'status' => 1,
            'short_code' => 'pit',
            'long_code' => 'paylater_installment',
            'payment_method_code' => 'UNZER_PAYLATER_INSTALLMENT',
            'name' => 'Unzer Paylater Installment',
            'class_name' => 'UnzerPaylaterInstallmentPaymentMethod',
        ],
        'post_finance_card' => [
            'status' => 1,
            'short_code' => 'pfc',
            'long_code' => 'post_finance_card',
            'payment_method_code' => 'UNZER_POST_FINANCE_CARD',
            'name' => 'Unzer Post Finance Card',
            'class_name' => 'UnzerPostFinanceCardPaymentMethod',
        ],
        'post_finance_efinance' => [
            'status' => 1,
            'short_code' => 'pfe',
            'long_code' => 'post_finance_efinance',
            'payment_method_code' => 'UNZER_POST_FINANCE_EFINANCE',
            'name' => 'Unzer Post Finance eFinance',
            'class_name' => 'UnzerPostFinanceEfinancePaymentMethod',
        ],
        'prepayment' => [
            'status' => 1,
            'short_code' => 'ppy',
            'long_code' => 'prepayment',
            'payment_method_code' => 'UNZER_PREPAYMENT',
            'name' => 'Unzer Prepayment',
            'class_name' => 'UnzerPrepaymentPaymentMethod',
        ],
        'przelewy24' => [
            'status' => 1,
            'short_code' => 'p24',
            'long_code' => 'przelewy24',
            'payment_method_code' => 'UNZER_PRZELEWY24',
            'name' => 'Unzer Przelewy24',
            'class_name' => 'UnzerPrzelewy24PaymentMethod',
        ],
        'sepa_direct_debit' => [
            'status' => 1,
            'short_code' => 'sdd',
            'long_code' => 'sepa_direct_debit',
            'payment_method_code' => 'UNZER_SEPA_DIRECT_DEBIT',
            'name' => 'Unzer SEPA Direct Debit',
            'class_name' => 'UnzerSepaDirectDebitPaymentMethod',
        ],
        'twint' => [
            'status' => 1,
            'short_code' => 'twt',
            'long_code' => 'twint',
            'payment_method_code' => 'UNZER_TWINT',
            'name' => 'Unzer TWINT',
            'class_name' => 'UnzerTwintPaymentMethod',
        ],
        'wechatpay' => [
            'status' => 1,
            'short_code' => 'wcp',
            'long_code' => 'wechatpay',
            'payment_method_code' => 'UNZER_WECHATPAY',
            'name' => 'Unzer WeChat Pay',
            'class_name' => 'UnzerWechatpayPaymentMethod',
        ],
    ];
}