<?php

namespace Tests\Unit\Services;

use App\Services\CheckoutExpiredReservationReleaseService;
use App\Services\CheckoutUtcTimestamp;
use Tests\TestCase;

final class CheckoutExpiredReservationReleaseServiceTest extends TestCase
{
    private function eligibleAt(string $now):bool
    {
        $reflection=new \ReflectionClass(CheckoutExpiredReservationReleaseService::class);
        $service=$reflection->newInstanceWithoutConstructor();
        $timestamps=$reflection->getProperty('timestamps');$timestamps->setValue($service,new CheckoutUtcTimestamp);
        $expired=$reflection->getMethod('expired');
        return $expired->invoke($service,'2026-09-25T12:00:00.347Z',new \DateTimeImmutable($now));
    }
    public function test_grace_boundary_is_exact_to_the_millisecond():void
    {
        $this->assertFalse($this->eligibleAt('2026-09-25T12:10:00.346Z'));
        $this->assertTrue($this->eligibleAt('2026-09-25T12:10:00.347Z'));
        $this->assertTrue($this->eligibleAt('2026-09-25T12:10:00.348Z'));
    }
}
