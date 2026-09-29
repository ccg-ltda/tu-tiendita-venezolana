<?php

namespace Tests\Unit\Services;

use App\Exceptions\CheckoutConsistencyException;
use App\Services\CheckoutUtcTimestamp;
use Tests\TestCase;

class CheckoutUtcTimestampTest extends TestCase
{
    public function test_it_parses_canonical_utc_milliseconds(): void
    { $this->assertSame(['iso'=>'2026-09-25T12:00:00.123Z','epoch_ms'=>1790337600123],(new CheckoutUtcTimestamp)->parse('2026-09-25T12:00:00.123Z')); }
    /** @dataProvider invalid */
    public function test_it_rejects_non_canonical_timestamps(string $value): void
    { $this->expectException(CheckoutConsistencyException::class);(new CheckoutUtcTimestamp)->parse($value); }
    public static function invalid(): array
    { return [['2026-09-25T12:00:00Z'],['2026-09-25T12:00:00.000+00:00'],['2026-02-30T12:00:00.000Z'],['invalid']]; }
}
