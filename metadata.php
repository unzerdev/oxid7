<?php
/**
 * Metadata version
 */
$sMetadataVersion = '2.1';

/**
 * Module information
 */
$aModule = [
    'id' => 'unzer_payment',
    'title' => 'Unzer Payment',
    'description' => [
        'de' => 'Bezahlung mit Unzer',
        'en' => 'Payment with Unzer'
    ],
    'thumbnail' => 'admin/unzer_payment.png',
    'lang' => 'en',
    'version' => '1.2.0',
    'author' => 'Unzer GmbH',
    'email' => 'info@unzer.com',
    'url' => 'https://www.unzer.com/',
    'extend' => [
        \OxidEsales\Eshop\Application\Model\OrderArticle::class => \Unzer\UnzerPayment\Model\OrderArticle::class,
        \OxidEsales\Eshop\Application\Model\BasketReservation::class => \Unzer\UnzerPayment\Model\BasketReservation::class,
        \OxidEsales\Eshop\Application\Component\UserComponent::class => \Unzer\UnzerPayment\Component\UserComponent::class,
        \OxidEsales\Eshop\Application\Model\PaymentGateway::class => \Unzer\UnzerPayment\Model\PaymentGateway::class,
        \OxidEsales\Eshop\Application\Model\Payment::class => \Unzer\UnzerPayment\Model\Payment::class,
        \OxidEsales\Eshop\Application\Model\Order::class => \Unzer\UnzerPayment\Model\Order::class,
        \OxidEsales\Eshop\Core\ViewConfig::class => \Unzer\UnzerPayment\Core\ViewConfig::class,
        \OxidEsales\Eshop\Application\Controller\Admin\ModuleConfiguration::class => \Unzer\UnzerPayment\Controller\Admin\ModuleConfiguration::class,
        \OxidEsales\Eshop\Application\Controller\Admin\OrderList::class => \Unzer\UnzerPayment\Controller\Admin\OrderList::class,
        \OxidEsales\Eshop\Application\Controller\OrderController::class => \Unzer\UnzerPayment\Controller\OrderController::class,
        \OxidEsales\Eshop\Application\Controller\PaymentController::class => \Unzer\UnzerPayment\Controller\PaymentController::class,
        \OxidEsales\Eshop\Application\Controller\ThankYouController::class => \Unzer\UnzerPayment\Controller\ThankYouController::class,
    ],
    'controllers' => [
        'unzer_payment_express' => \Unzer\UnzerPayment\Controller\ExpressController::class,
        'unzer_payment_redirect' => \Unzer\UnzerPayment\Controller\RedirectController::class,
        'unzer_payment_webhook' => \Unzer\UnzerPayment\Controller\WebhookController::class,
        'unzer_payment_admin_order'   => \Unzer\UnzerPayment\Controller\Admin\AdminOrderController::class,
    ],
    'events' => [
        'onActivate' => \Unzer\UnzerPayment\Core\Events::class . '::onActivate',
        'onDeactivate' => \Unzer\UnzerPayment\Core\Events::class . '::onDeactivate',
    ],
    'settings' => [
        ['group' => 'unzer_payment_main', 'name' => 'blUnzerPreCreateOrder', 'type' => 'bool', 'value' => false],
        [
            'group' => 'unzer_payment_main',
            'name' => 'UnzerPaymentMode',
            'type' => 'select',
            'value' => '0',
            'constraints' => '0|1'
        ],
        [
            'group' => 'unzer_payment_main',
            'name' => 'UnzerPaymentPublicKey',
            'type' => 'str',
            'value' => ''
        ],
        [
            'group' => 'unzer_payment_main',
            'name' => 'UnzerPaymentPrivateKey',
            'type' => 'str',
            'value' => ''
        ],
        [
            'group' => 'unzer_payment_main',
            'name' => 'UnzerPaymentLogLevel',
            'type' => 'select',
            'value' => 'ERROR',
            'constraints' => 'ERROR|WARNING|DEBUG'
        ],
        [
            'group' => 'unzer_payment_specific',
            'name' => 'UnzerPaymentCardChargeMode',
            'type' => 'select',
            'value' => 'authorize|charge',
            'constraints' => 'authorize|charge',
        ],
        [
            'group' => 'unzer_payment_specific',
            'name' => 'UnzerPaymentApplepayChargeMode',
            'type' => 'select',
            'value' => 'authorize|charge',
            'constraints' => 'authorize|charge',
        ],
        [
            'group' => 'unzer_payment_specific',
            'name' => 'UnzerPaymentGooglepayChargeMode',
            'type' => 'select',
            'value' => 'authorize|charge',
            'constraints' => 'authorize|charge',
        ],
        [
            'group' => 'unzer_payment_specific',
            'name' => 'UnzerPaymentPaypalChargeMode',
            'type' => 'select',
            'value' => 'authorize|charge',
            'constraints' => 'authorize|charge',
        ],
        [
            'group' => 'unzer_payment_specific',
            'name' => 'UnzerPaymentApplepayExpressCheckout',
            'type' => 'bool',
            'value' => false
        ],
        [
            'group' => 'unzer_payment_specific',
            'name' => 'UnzerPaymentPaypalExpressCheckout',
            'type' => 'bool',
            'value' => false
        ],
        [
            'group' => 'unzer_payment_specific',
            'name' => 'UnzerPaymentGooglepayExpressCheckout',
            'type' => 'bool',
            'value' => false
        ],
        [
            'group' => 'unzer_payment_specific',
            'name' => 'UnzerPaymentGooglepayMerchantId',
            'type' => 'str',
            'value' => ''
        ],
        [
            'group' => 'unzer_payment_specific',
            'name' => 'UnzerPaymentGooglepayMerchantName',
            'type' => 'str',
            'value' => ''
        ],
        /*
        [
            'group' => 'unzer_payment_specific',
            'name' => 'UnzerPaymentWeroChargeMode',
            'type' => 'select',
            'value' => 'authorize|charge',
            'constraints' => 'authorize|charge',
        ],
        */
        [
            'group' => 'unzer_payment_specific',
            'name' => 'UnzerPaymentCOFCreditCard',
            'type' => 'bool',
            'value' => false
        ],
        [
            'group' => 'unzer_payment_specific',
            'name' => 'UnzerPaymentCOFPaypal',
            'type' => 'bool',
            'value' => false
        ],
        [
            'group' => 'unzer_payment_specific',
            'name' => 'UnzerPaymentCOFDirectDebit',
            'type' => 'bool',
            'value' => false
        ],
        [
            'group' => 'unzer_payment_specific',
            'name' => 'UnzerPaymentClickToPay',
            'type' => 'bool',
            'value' => false
        ]
    ],
];
