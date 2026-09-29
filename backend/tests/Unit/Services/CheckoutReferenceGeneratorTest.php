<?php

namespace Tests\Unit\Services;

use App\Services\CheckoutReferenceGenerator;
use DateTimeImmutable;
use Tests\TestCase;

class CheckoutReferenceGeneratorTest extends TestCase
{
    public function test_it_uses_the_exact_reference_format_bogota_date_and_uppercase_base36(): void
    {
        $reference=(new CheckoutReferenceGenerator)->generate(17,new DateTimeImmutable('2026-09-26T00:30:00.000Z'),'35aaa0ff-1111-4111-8111-111111111111');
        $this->assertSame('TTV-20260925-H-35AAA0FF',$reference);
        $this->assertMatchesRegularExpression('/^TTV-\d{8}-[0-9A-Z]+-[0-9A-F]{8}$/',$reference);
    }
}
