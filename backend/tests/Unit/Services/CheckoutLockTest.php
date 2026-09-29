<?php

namespace Tests\Unit\Services;

use App\Exceptions\CheckoutLockTimeoutException;
use App\Services\CheckoutLock;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class CheckoutLockTest extends TestCase
{
    public function test_it_runs_the_callback_and_releases_the_lock(): void
    {
        $lock = new CheckoutLock;
        $this->assertSame('done', $lock->run(fn () => 'done'));
        $next = Cache::lock(CheckoutLock::NAME, 10);
        $this->assertTrue($next->get());
        $next->release();
    }

    public function test_it_releases_the_lock_when_the_callback_throws(): void
    {
        $lock = new CheckoutLock;
        try { $lock->run(fn () => throw new \RuntimeException('expected')); $this->fail('Expected callback exception.'); } catch (\RuntimeException) {}
        $next = Cache::lock(CheckoutLock::NAME, 10);
        $this->assertTrue($next->get());
        $next->release();
    }

    public function test_it_throws_when_the_lock_cannot_be_acquired(): void
    {
        // Hold longer than CheckoutLock's 15-second bounded wait.
        $held = Cache::lock(CheckoutLock::NAME, 20);
        $this->assertTrue($held->get());
        try {
            $this->expectException(CheckoutLockTimeoutException::class);
            (new CheckoutLock)->run(fn () => null);
        } finally { $held->release(); }
    }

    public function test_it_waits_up_to_fifteen_seconds_before_failing_closed(): void
    {
        $nativeLock = Mockery::mock(\Illuminate\Contracts\Cache\Lock::class);
        $nativeLock->shouldReceive('block')->once()->with(15)->andReturnNull();
        $nativeLock->shouldReceive('release')->once();
        Cache::shouldReceive('lock')->once()->with(CheckoutLock::NAME, 60)->andReturn($nativeLock);

        $this->assertSame('acquired-after-a-longer-wait', (new CheckoutLock)->run(fn () => 'acquired-after-a-longer-wait'));
    }
}
