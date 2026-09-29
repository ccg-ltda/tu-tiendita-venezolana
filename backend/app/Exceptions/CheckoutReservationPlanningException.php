<?php

namespace App\Exceptions;

use RuntimeException;

final class CheckoutReservationPlanningException extends RuntimeException
{
    public function __construct(private readonly string $checkoutCode)
    {
        parent::__construct('No fue posible planificar el checkout.');
    }

    public function checkoutCode(): string
    {
        return $this->checkoutCode;
    }
}
