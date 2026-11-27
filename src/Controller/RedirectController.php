<?php

namespace Unzer\UnzerPayment\Controller;

use OxidEsales\Eshop\Application\Controller\FrontendController;
use OxidEsales\Eshop\Core\Registry;
use Unzer\UnzerPayment\Classes\UnzerpaymentClient;
use Unzer\UnzerPayment\Model\Order;
use Unzer\UnzerPayment\Model\PaymentGateway;
use Unzer\UnzerPayment\Service\RedirectSessionService;
use Unzer\UnzerPayment\Service\PaymentService;

class RedirectController extends FrontendController
{
    public function paymentReturn(): void
    {
        $session = Registry::getSession();

        $redirectSession = new RedirectSessionService();
        $orderId = (string) $redirectSession->getOrderId();
        $paymentId = (string) $redirectSession->getPaymentId();

        if ($orderId === '' || $paymentId === '') {
            $this->redirectToPaymentError(
                'Missing Unzer redirect session data.'
            );
        }

        /** @var Order $order */
        $order = oxNew(
            \OxidEsales\Eshop\Application\Model\Order::class
        );

        if (!$order->load($orderId)) {
            $this->redirectToPaymentError(
                'Could not load pending OXID order: ' . $orderId
            );
        }

        /*
         * Already finalized?
         *
         * Can happen on browser reload.
         */
        if (
            (string) $order->oxorder__oxtransstatus->value
            === 'OK'
        ) {
            $this->clearRedirectSession();

            $this->redirectToThankYou();
        }

        try {
            $paymentService = new PaymentService();

            $payment = UnzerpaymentClient::getInstance()
                ->fetchPayment($paymentId);

            if (!$paymentService->isPaymentSuccessful($payment)) {
                throw new \RuntimeException('Unzer payment is not successful.');
            }

            $basket = $session->getBasket();

            if (
                !$basket
                || !$basket->getProductsCount()
            ) {
                throw new \RuntimeException(
                    'OXID basket is not available after payment redirect.'
                );
            }

            $user = $order->getOrderUser();

            if (!$user || !$user->getId()) {
                throw new \RuntimeException(
                    'Could not load OXID order user.'
                );
            }

            $result = $order->finalizeUnzerRedirectOrder(
                $basket,
                $user
            );

            $user->onOrderExecute(
                $basket,
                $result
            );

            if (
                $result !== Order::ORDER_STATE_OK
                && $result !== Order::ORDER_STATE_MAILINGERROR
            ) {
                throw new \RuntimeException(
                    'Could not finalize OXID order. State: '
                    . $result
                );
            }

            $this->clearRedirectSession();

            if (
                $result === Order::ORDER_STATE_MAILINGERROR
            ) {
                $this->redirectToThankYou(
                    true
                );
            }

            $this->redirectToThankYou();
        } catch (\Throwable $exception) {
            Registry::getLogger()->error(
                'Unzer redirect payment return failed',
                [
                    'orderId' => $orderId,
                    'paymentId' => $paymentId,
                    'exception' => $exception,
                ]
            );

            $this->redirectToPaymentError(
                $exception->getMessage()
            );
        }
    }

    private function clearRedirectSession(): void
    {
        (new RedirectSessionService())->clear();
    }

    private function redirectToThankYou(
        bool $mailError = false
    ): never {
        $url = $this->getShopUrl()
            . 'cl=thankyou';

        if ($mailError) {
            $url .= '&mailerror=1';
        }

        Registry::getUtils()->redirect(
            $url,
            false,
            302
        );

        exit;
    }

    private function redirectToPaymentError(
        string $message
    ): never {
        Registry::getSession()->setVariable(
            'payerror',
            -1
        );

        Registry::getUtilsView()->addErrorToDisplay('UNZER_PAYMENT_ERROR');

        Registry::getUtils()->redirect(
            $this->getShopUrl()
            . 'cl=payment'
            . '&payerror=-1',
            false,
            302
        );

        exit;
    }

    private function getShopUrl(): string
    {
        return html_entity_decode(
            Registry::getConfig()->getShopSecureHomeUrl(),
            ENT_QUOTES | ENT_HTML5
        );
    }
}