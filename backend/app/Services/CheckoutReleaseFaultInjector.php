<?php

namespace App\Services;

/** Internal seam for unit tests and the guarded LAB command; never HTTP-reachable. */
class CheckoutReleaseFaultInjector
{
    public function after(string $checkpoint): void {}
}
