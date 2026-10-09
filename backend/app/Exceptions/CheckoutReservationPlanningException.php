<?php

namespace App\Exceptions;

use RuntimeException;

final class CheckoutReservationPlanningException extends RuntimeException
{
    /** @param array<string,scalar|null> $context */
    public function __construct(private readonly string $checkoutCode, private readonly array $context = [])
    {
        parent::__construct('No fue posible planificar el checkout.');
    }

    public function checkoutCode(): string
    {
        return $this->checkoutCode;
    }

    /** @return array<string,scalar|null> */
    public function context(): array
    {
        return $this->context;
    }
}
