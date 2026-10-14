<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureWebhookPayloadSize;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class WompiWebhookHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.wompi.events_secret', 'wompi-webhook-test-secret');
        RateLimiter::clear('wompi-webhook:127.0.0.1');
    }

    public function test_an_oversized_wompi_payload_is_rejected_before_processing(): void
    {
        $this->rawWebhook(str_repeat('x', EnsureWebhookPayloadSize::MAX_BYTES + 1))
            ->assertStatus(413);
    }

    public function test_the_wompi_webhook_rate_limit_is_applied(): void
    {
        for ($attempt = 0; $attempt < 120; $attempt++) {
            $this->rawWebhook('{}')->assertUnprocessable();
        }

        $this->rawWebhook('{}')->assertTooManyRequests();
    }

    private function rawWebhook(string $rawBody): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', '/api/webhooks/wompi', [], [], [], ['CONTENT_TYPE' => 'application/json'], $rawBody);
    }
}
