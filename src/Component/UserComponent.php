<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Component;

use OxidEsales\Eshop\Core\Registry;
use Unzer\UnzerPayment\Service\ExpressCheckoutService;
use Unzer\UnzerPayment\Service\ExpressUserService;

class UserComponent extends UserComponent_parent
{
    protected function changeUserWithoutRedirect()
    {
        $user = $this->getUser();
        if (!$user || !ExpressUserService::isExpressGuest($user)) {
            return parent::changeUserWithoutRedirect();
        }

        $session = Registry::getSession();
        $contact = $session->getVariable(ExpressCheckoutService::SESSION_GUEST_CONTACT);
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
            || !$session->checkSessionChallenge()
            || $session->getVariable('usr') !== $user->getId()
            || ($contact['userId'] ?? null) !== $user->getId()
        ) {
            return false;
        }

        $request = Registry::getRequest();
        $address = $request->getRequestParameter('invadr');
        if (!is_array($address)) {
            return parent::changeUserWithoutRedirect();
        }

        $email = $address['oxuser__oxusername'] ?? ($contact['email'] ?? null);
        if (!is_string($email) || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
            Registry::getUtilsView()->addErrorToDisplay('ERROR_MESSAGE_INPUT_NOVALIDEMAIL', false, true);
            return false;
        }

        // Keep the contact out of OXID's username update and guest deduplication.
        unset($address['oxuser__oxusername']);
        $originalPost = $_POST['invadr'] ?? null;
        $_POST['invadr'] = $address;
        try {
            $result = parent::changeUserWithoutRedirect();
        } finally {
            if ($originalPost === null) {
                unset($_POST['invadr']);
            } else {
                $_POST['invadr'] = $originalPost;
            }
        }

        if ($result === true) {
            $contact['email'] = trim($email);
            $session->setVariable(ExpressCheckoutService::SESSION_GUEST_CONTACT, $contact);
        }

        return $result;
    }
}
