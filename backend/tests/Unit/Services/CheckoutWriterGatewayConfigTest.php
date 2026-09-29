<?php

namespace Tests\Unit\Services;

use App\Services\CheckoutWriterGateway;
use Tests\TestCase;

final class CheckoutWriterGatewayConfigTest extends TestCase
{
    public function test_default_and_explicit_apps_script_remain_apps_script(): void
    {
        config()->set('checkout.writer_backend', 'apps_script');
        $this->assertSame('apps_script', app(CheckoutWriterGateway::class)->backend());
    }

    public function test_explicit_direct_is_the_only_value_that_selects_direct(): void
    {
        config()->set('checkout.writer_backend', 'direct');
        $this->assertSame('direct', app(CheckoutWriterGateway::class)->backend());
    }

    public function test_invalid_value_falls_closed_to_apps_script(): void
    {
        config()->set('checkout.writer_backend', 'unexpected');
        $this->assertSame('apps_script', app(CheckoutWriterGateway::class)->backend());
    }
}
