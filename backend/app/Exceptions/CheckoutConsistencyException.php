<?php

namespace App\Exceptions;

use RuntimeException;

final class CheckoutConsistencyException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('El estado del checkout no es consistente.');
    }
}
