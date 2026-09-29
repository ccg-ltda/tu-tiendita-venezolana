<?php

namespace App\Services;

use App\Exceptions\CheckoutLockTimeoutException;
use Illuminate\Contracts\Cache\LockTimeoutException as LaravelLockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class CheckoutLock
{
    public const NAME = 'checkout:global-write-lock';
    private const LEASE_SECONDS = 60;
    // A verified Sheets checkout can take several seconds; this remains bounded
    // and fails closed if the critical section is still unavailable.
    private const WAIT_SECONDS = 15;

    public function run(callable $callback): mixed
    {
        $lock = Cache::lock(self::NAME, self::LEASE_SECONDS);

        try {
            $lock->block(self::WAIT_SECONDS);
        } catch (LaravelLockTimeoutException) {
            throw new CheckoutLockTimeoutException;
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
