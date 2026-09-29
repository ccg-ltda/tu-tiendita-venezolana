<?php

namespace App\Services;

/**
 * Internal test seam. Production construction supplies no injector and this
 * class does nothing; it is never reachable from HTTP input.
 */
class CheckoutWriteFaultInjector
{
    public function after(string $checkpoint): void {}
}
