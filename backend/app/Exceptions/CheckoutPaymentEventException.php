<?php

namespace App\Exceptions;

use RuntimeException;

final class CheckoutPaymentEventException extends RuntimeException
{
    public function __construct(public readonly string $paymentCode)
    {
        parent::__construct($paymentCode);
    }
}
