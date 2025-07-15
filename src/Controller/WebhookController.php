<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Controller;

use OxidEsales\Eshop\Application\Controller\FrontendController;
use Unzer\UnzerPayment\Classes\UnzerpaymentClient;
use Unzer\UnzerPayment\Classes\UnzerpaymentHelper;
use Unzer\UnzerPayment\Service\DebugHandler;
use Unzer\UnzerPayment\Traits\ServiceContainer;
use UnzerSDK\Constants\WebhookEvents;

class WebhookController extends FrontendController
{

    use ServiceContainer;

    const REGISTERED_EVENTS = array(
        WebhookEvents::CHARGE_CANCELED,
        WebhookEvents::AUTHORIZE_CANCELED,
        WebhookEvents::AUTHORIZE_SUCCEEDED,
        WebhookEvents::CHARGE_SUCCEEDED,
        WebhookEvents::PAYMENT_CHARGEBACK,
    );

    protected UnzerpaymentClient $unzer;

    protected DebugHandler $logger;

    /**
     * @return void
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function notify()
    {
        $this->unzer = UnzerpaymentClient::getInstance();
        $this->logger = $this->getServiceFromContainer(DebugHandler::class);
        $jsonRequest = file_get_contents('php://input');
        $data = json_decode($jsonRequest, true);

        if ( empty( $data ) ) {
            header("HTTP/1.0 404 Not Found");
            $this->logger->addLog('empty webhook call', 2,false, [
                'server' => $_SERVER
            ]);
            exit();
        }

        if ( ! in_array( $data['event'], self::REGISTERED_EVENTS, true ) ) {
            $this->renderJson(
                array(
                    'success' => true,
                    'msg'     => 'event not relevant',
                )
            );
        }

        $this->logger->addLog('webhook received',  3,false, [
            'data' => $data
        ]);
        if ( empty( $data['paymentId'] ) ) {
            $this->logger->addLog('no payment id in webhook event', 2,false, [
                'data' => $data
            ]);
            exit();
        }

        $orderId = UnzerpaymentHelper::getInstance()->getOrderIdByTransactionId(
            $data['paymentId']
        );
        if ( empty( $orderId ) ) {
            $this->logger->addLog('no order id for webhook event found', 2,false, [
                'data' => $data
            ]);
            exit();
        }
        $eventHash = 'unzer_event_' . md5( $data['paymentId'] . '|' . $data['event'] );

        switch ( $data['event'] ) {
            case WebhookEvents::CHARGE_CANCELED:
            case WebhookEvents::AUTHORIZE_CANCELED:
                $this->handleCancel( $data['paymentId'], $orderId );
                break;
            case WebhookEvents::AUTHORIZE_SUCCEEDED:
                $this->handleAuthorizeSucceeded( $data['paymentId'], $orderId );
                break;
            case WebhookEvents::CHARGE_SUCCEEDED:
                $this->handleChargeSucceeded( $data['paymentId'], $orderId );
                break;
            case WebhookEvents::PAYMENT_CHARGEBACK:
                $this->handleChargeback( $data['paymentId'], $orderId );
                break;
        }

        $this->renderJson( array( 'success' => true ) );
    }

    /**
     * @param $paymentId
     * @param $orderId
     * @return void
     * @throws \OxidEsales\Eshop\Core\Exception\DatabaseConnectionException
     * @throws \OxidEsales\Eshop\Core\Exception\DatabaseErrorException
     */
    public function handleChargeback( $paymentId, $orderId ) {
        $this->logger->addLog('webhook handleChargeback', 3, false, [
            'paymentId' => $paymentId,
            'orderId' => $orderId
        ]);
        UnzerpaymentHelper::getInstance()->setOrderStatus(
            $orderId,
            'CHARGEBACK'
        );
    }

    /**
     * @param $paymentId
     * @param $orderId
     * @return void
     * @throws \OxidEsales\Eshop\Core\Exception\DatabaseConnectionException
     * @throws \OxidEsales\Eshop\Core\Exception\DatabaseErrorException
     */
    private function handleCancel( $paymentId, $orderId ) {
        $this->logger->addLog('webhook handleCancle', 3, false, [
            'paymentId' => $paymentId,
            'orderId' => $orderId
        ]);
        UnzerpaymentHelper::getInstance()->setOrderStatus(
            $orderId,
            'CANCELED'
        );
    }

    /**
     * @param $paymentId
     * @param $orderId
     * @return void
     * @throws \OxidEsales\Eshop\Core\Exception\DatabaseConnectionException
     * @throws \OxidEsales\Eshop\Core\Exception\DatabaseErrorException
     */
    private function handleAuthorizeSucceeded( $paymentId, $orderId ) {
        $this->logger->addLog('webhook handleAuthorizeSucceeded', 3, false, [
            'paymentId' => $paymentId,
            'orderId' => $orderId
        ]);
        UnzerpaymentHelper::getInstance()->setOrderStatus(
            $orderId,
            'AUTHORIZED'
        );
    }

    /**
     * @param $paymentId
     * @param $orderId
     * @return void
     * @throws \OxidEsales\Eshop\Core\Exception\DatabaseConnectionException
     * @throws \OxidEsales\Eshop\Core\Exception\DatabaseErrorException
     */
    private function handleChargeSucceeded( $paymentId, $orderId ) {
        $this->logger->addLog('webhook handleChargeSucceeded', 3, false, [
            'paymentId' => $paymentId,
            'orderId' => $orderId
        ]);
        UnzerpaymentHelper::getInstance()->setOrderStatus(
            $orderId,
            'OK'
        );
        UnzerpaymentHelper::getInstance()->setOrderPaid(
            $orderId
        );
    }


    /**
     * @param $data
     * @return void
     */
    protected function renderJson($data) {
        header( 'Content-Type: application/json' );
        echo json_encode($data);
        die;
    }

}