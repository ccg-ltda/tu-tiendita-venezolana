<?php

namespace Tests\Feature\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WompiWebhookTest extends TestCase
{
    private const URL = 'https://apps-script.test/exec';
    private const EVENT_TIMESTAMP = 1_725_000_000_000;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.wompi.events_secret' => 'test_events_secret',
            'services.apps_script.url' => self::URL,
            'services.apps_script.api_key' => 'apps-script-test-key',
        ]);
    }

    public function test_millisecond_timestamp_sends_exact_utc_timestamp_to_apps_script(): void
    {
        Http::fake([self::URL => Http::response($this->success(), 200)]);

        $this->webhook($this->payload('APPROVED'))->assertOk()->assertJson(['status' => 'ok']);

        Http::assertSent(function ($request): bool {
            $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

            return $body['action'] === 'record_payment_event'
                && $body['api_key'] === 'apps-script-test-key'
                && $body['transaction'] === [
                    'id' => 'transaction-1',
                    'reference' => 'TTV-WEBHOOK-1',
                    'status' => 'APPROVED',
                    'payment_method' => 'CARD',
                    'amount_in_cents' => 100000,
                    'currency' => 'COP',
                    'event_occurred_at' => '2024-08-30T06:40:00.000Z',
                ];
        });
    }

    public function test_second_timestamp_sends_exact_utc_timestamp_to_apps_script(): void
    {
        Http::fake([self::URL => Http::response($this->success(), 200)]);
        $payload = $this->payload('APPROVED');
        $payload['timestamp'] = 1_725_000_000;
        $this->sign($payload);

        $this->webhook($payload)->assertOk();

        Http::assertSent(function ($request): bool {
            $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

            return $body['transaction']['event_occurred_at'] === '2024-08-30T06:40:00.000Z';
        });
    }

    public function test_invalid_signature_header_checksum_payload_and_event_never_call_apps_script(): void
    {
        Http::fake();
        $invalidSignature = $this->payload('PENDING');
        $invalidSignature['signature']['checksum'] = str_repeat('0', 64);
        $this->webhook($invalidSignature)->assertUnauthorized();

        $invalidHeader = $this->payload('PENDING');
        $this->withHeader('X-Event-Checksum', str_repeat('f', 64))->webhook($invalidHeader)->assertUnauthorized();

        $unsupported = $this->payload('PENDING');
        $unsupported['event'] = 'nequi_token.updated';
        $this->webhook($unsupported)->assertUnprocessable();

        $incomplete = $this->payload('PENDING');
        unset($incomplete['data']['transaction']['reference']);
        $this->webhook($incomplete)->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_signature_properties_are_resolved_in_the_received_order(): void
    {
        Http::fake([self::URL => Http::response($this->success(), 200)]);
        $payload = $this->payload('PENDING');
        $payload['signature']['properties'] = [
            'transaction.currency',
            'transaction.reference',
            'transaction.id',
            'transaction.amount_in_cents',
            'transaction.status',
        ];
        $this->sign($payload);

        $this->webhook($payload)->assertOk();
        Http::assertSentCount(1);
    }

    public function test_every_supported_wompi_status_is_forwarded(): void
    {
        $responses = Http::sequence();
        foreach (['PENDING', 'APPROVED', 'DECLINED', 'VOIDED', 'ERROR'] as $status) {
            $responses->push($this->success(eventResult: $status === 'APPROVED' ? 'APPROVED' : 'RECORDED'), 200);
        }
        Http::fake([self::URL => $responses]);

        foreach (['PENDING', 'APPROVED', 'DECLINED', 'VOIDED', 'ERROR'] as $index => $status) {
            $this->webhook($this->payload($status, transactionId: 'transaction-'.$index))->assertOk();
        }

        Http::assertSentCount(5);
    }

    public function test_replay_and_late_approved_review_are_successful(): void
    {
        $replay = $this->success();
        $replay['data']['payment_event_replayed'] = true;
        $review = $this->success(eventResult: 'PAYMENT_REVIEW_REQUIRED');
        $review['data']['status'] = 'PAYMENT_REVIEW_REQUIRED';
        $review['data']['payment_status'] = 'APPROVED';
        $review['data']['reservation_status'] = 'RELEASED';
        Http::fake([self::URL => Http::sequence()->push($replay, 200)->push($review, 200)]);

        $this->webhook($this->payload('APPROVED'))->assertOk();
        $this->webhook($this->payload('APPROVED', transactionId: 'transaction-late'))->assertOk();
    }

    public function test_apps_script_timeout_fails_closed(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timeout'));
        $this->webhook($this->payload('PENDING'))->assertStatus(504);
    }

    public function test_invalid_json_and_incomplete_apps_script_response_fail_closed(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->push('not-json', 200)
            ->push(['ok' => true, 'data' => ['order_id' => 1]], 200)]);
        $this->webhook($this->payload('PENDING', transactionId: 'transaction-json'))->assertStatus(502);
        $this->webhook($this->payload('PENDING', transactionId: 'transaction-incomplete'))->assertStatus(502);
    }

    public function test_missing_method_uses_only_the_documented_technical_fallback_and_secrets_are_not_returned(): void
    {
        Http::fake([self::URL => Http::response($this->success(), 200)]);
        $payload = $this->payload('PENDING');
        unset($payload['data']['transaction']['payment_method_type']);
        $this->sign($payload);

        $body = $this->webhook($payload)->assertOk()->getContent();
        Http::assertSent(fn ($request): bool => json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR)['transaction']['payment_method'] === 'UNKNOWN');
        $this->assertStringNotContainsString('test_events_secret', $body);
        $this->assertStringNotContainsString('apps-script-test-key', $body);
    }

    public function test_missing_events_secret_never_calls_apps_script(): void
    {
        Http::fake();
        config(['services.wompi.events_secret' => null]);

        $this->webhook($this->payload('PENDING'))->assertStatus(503);
        Http::assertNothingSent();
    }

    /** @return array<string, mixed> */
    private function payload(string $status, string $transactionId = 'transaction-1'): array
    {
        $payload = [
            'event' => 'transaction.updated',
            'data' => ['transaction' => [
                'id' => $transactionId,
                'reference' => 'TTV-WEBHOOK-1',
                'status' => $status,
                'amount_in_cents' => 100000,
                'currency' => 'COP',
                'payment_method_type' => 'CARD',
            ]],
            'signature' => ['properties' => [
                'transaction.id',
                'transaction.status',
                'transaction.amount_in_cents',
            ], 'checksum' => ''],
            'timestamp' => self::EVENT_TIMESTAMP,
        ];
        $this->sign($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function sign(array &$payload): void
    {
        $values = '';
        foreach ($payload['signature']['properties'] as $property) {
            $value = $payload['data'];
            foreach (explode('.', $property) as $segment) {
                $value = $value[$segment];
            }
            $values .= (string) $value;
        }
        $payload['signature']['checksum'] = hash('sha256', $values.$payload['timestamp'].'test_events_secret');
    }

    /** @param array<string, mixed> $payload */
    private function webhook(array $payload)
    {
        return $this->postJson('/api/webhooks/wompi', $payload);
    }

    private function success(string $eventResult = 'RECORDED'): array
    {
        return ['ok' => true, 'data' => [
            'order_id' => 77,
            'payment_attempt_id' => 12,
            'payment_event_replayed' => false,
            'event_result' => $eventResult,
            'status' => 'PENDING',
            'payment_status' => 'PENDING',
            'reservation_status' => 'ACTIVE',
            'revision' => 2,
        ]];
    }
}
