<?php

namespace Unzer\UnzerPayment\Service;

use \Exception;
use OxidEsales\Eshop\Core\Registry;
use Unzer\UnzerPayment\Service\MetadataService;
use Unzer\UnzerPayment\Classes\UnzerpaymentClient;
use Unzer\UnzerPayment\Traits\ServiceContainer;
use UnzerSDK\Exceptions\UnzerApiException;
use UnzerSDK\Resources\Payment;
use UnzerSDK\Resources\TransactionTypes\AbstractTransactionType;
use UnzerSDK\Resources\TransactionTypes\Authorization;
use UnzerSDK\Resources\TransactionTypes\Charge;

class PaymentService
{
    use ServiceContainer;
    /**
     * @throws UnzerApiException
     */
    public function performChargeForOrder(
        $order,
        $paymentGateway,
        $paymentType,
        $chargeEditor = null
    ): Charge {
        return $this->performChargeOrAuthorizationForOrder( $order, $paymentGateway, $paymentType, $chargeEditor );
    }

    /**
     * @param $order
     * @param $paymentGateway
     * @param $paymentType
     * @param $chargeEditor
     * @return Authorization
     * @throws UnzerApiException
     */
    public function performAuthorizationForOrder(
        $order,
        $paymentGateway,
        $paymentType,
        $chargeEditor = null
    ): Authorization {
        return $this->performChargeOrAuthorizationForOrder( $order, $paymentGateway, $paymentType, $chargeEditor, 'authorize' );
    }

    /**
     * @param $order
     * @param $paymentGateway
     * @param $paymentType
     * @param callable|null   $transactionEditor
     * @param string          $type
     * @return Charge|Authorization
     * @throws UnzerApiException|\Exception
     */
    protected function performChargeOrAuthorizationForOrder(
        $order,
        $paymentGateway,
        $paymentType,
        callable $transactionEditor = null,
        string $type = 'charge'
    ): AbstractTransactionType {

        $orderId = (string)$order->getId();
        $basket   = ( new BasketService() )->getBasket( Registry::getSession()->getVariable('expressBasket'), $orderId );

        $returnURL = html_entity_decode(
                Registry::getConfig()->getShopSecureHomeUrl(),
                ENT_QUOTES | ENT_HTML5
            ) . 'cl=unzer_payment_redirect&fnc=paymentReturn';

        $customer = ( new CustomerService() )->getCustomerFromOrder( $order );

        $logger = $this->getServiceFromContainer(DebugHandler::class);

        try {
            $logger->addLog(
                'try authorization/charge for #' . $orderId,
                3,
                false,
                array(
                    'type'     => $type,
                    'basket'   => $basket->expose(),
                    'customer' => $customer->expose(),
                )
            );
            if ( $type === 'authorize' ) {
                $authorization = new Authorization( $basket->getTotalValueGross(), $basket->getCurrencyCode(), $returnURL );
                $authorization->setOrderId( $orderId );
                if ( $transactionEditor !== null ) {
                    $transactionEditor( $authorization );
                }
                $transactionObject = UnzerpaymentClient::getInstance()->performAuthorization( $authorization, $paymentType, $customer, ( new MetadataService() )->getMetadata(1), $basket );
            } else {
                $charge = new Charge( $basket->getTotalValueGross(), $basket->getCurrencyCode(), $returnURL );
                $charge->setOrderId( $orderId );
                if ( $transactionEditor !== null ) {
                    $transactionEditor( $charge );
                }
                $transactionObject = UnzerpaymentClient::getInstance()->performCharge( $charge, $paymentType, $customer, ( new MetadataService() )->getMetadata(1), $basket );
            }
        } catch ( Exception $e ) {
            throw $e;
        }
        return $transactionObject;
    }


    public function isPaymentSuccessful(Payment $payment): bool
    {
        $authorization = $payment->getAuthorization(true);
        if ($authorization !== null && $authorization->isSuccess()) {
            return true;
        }

        foreach ($payment->getCharges() as $charge) {
            if ($charge->isSuccess()) {
                return true;
            }
        }

        return false;
    }
}
