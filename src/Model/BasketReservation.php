<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Model;

class BasketReservation extends BasketReservation_parent
{
    public function unzerDiscardToken(string $token): void
    {
        $this->_oReservations = $this->loadReservations($token);
        $this->_aCurrentlyReserved = null;
        $this->discardReservations();
    }

    public function unzerResetCache(): void
    {
        $this->_oReservations = null;
        $this->_aCurrentlyReserved = null;
    }
}
