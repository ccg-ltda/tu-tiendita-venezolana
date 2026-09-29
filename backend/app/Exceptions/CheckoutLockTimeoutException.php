<?php

namespace App\Exceptions;

use RuntimeException;

final class CheckoutLockTimeoutException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No fue posible obtener el bloqueo del checkout.');
    }
}
