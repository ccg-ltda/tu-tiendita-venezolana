<?php

namespace Tests\Feature\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WompiPaymentStatusTest extends TestCase
{
    private const URL = 'https://apps-script.test/exec';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.apps_script.url' => self::URL, 'services.apps_script.api_key' => 'apps-script-test-key']);
    }

    public function test_valid_token_calls_apps_script_with_its_exact_reference(): void
    {
        Http::fake([self::URL => Http::response($this->statusData(), 200)]);
        $token = $this->token('TTV-STATUS-1');

        $response = $this->fetchStatus($token)->assertOk();

        $response->assertExactJson(['checkout' => [
            'status' => 'PENDING',
            'reservation' => 'ACTIVE',
            'statusUpdatedAt' => '2026-09-21T13:04:20.166Z',
        ]]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString($token, $response->getContent());
        $this->assertStringNotContainsString('TTV-STATUS-1', $response->getContent());
        Http::assertSent(function ($request): bool {
            $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

            return $body === ['action' => 'get_checkout_status', 'api_key' => 'apps-script-test-key', 'reference' => 'TTV-STATUS-1'];
        });
    }

    public function test_absent_tampered_or_structurally_invalid_tokens_fail_closed_without_http(): void
    {
        Http::fake();
        $tokens = [
            null,
            'not-a-token',
            Crypt::encryptString('not-json'),
            Crypt::encryptString(json_encode(['v' => 2, 'reference' => 'TTV-STATUS-1', 'exp' => now()->addHour()->timestamp, 'nonce' => str_repeat('a', 32)], JSON_THROW_ON_ERROR)),
            $this->token('TTV-STATUS-1', now()->subSecond()->timestamp),
            Crypt::encryptString(json_encode(['v' => 1, 'reference' => ' invalid ', 'exp' => now()->addHour()->timestamp, 'nonce' => str_repeat('a', 32)], JSON_THROW_ON_ERROR)),
            Crypt::encryptString(json_encode(['v' => 1, 'reference' => 'TTV-STATUS-1', 'exp' => now()->addHour()->timestamp, 'nonce' => 'bad'], JSON_THROW_ON_ERROR)),
        ];

        foreach ($tokens as $token) {
            $this->fetchStatus($token)->assertNotFound()->assertExactJson(['error' => 'Checkout status unavailable.']);
        }

        Http::assertNothingSent();
    }

    public function test_response_reference_mismatch_fails_closed(): void
    {
        $data = $this->statusData();
        $data['data']['reference'] = 'TTV-OTHER';
        Http::fake([self::URL => Http::response($data, 200)]);

        $this->fetchStatus($this->token('TTV-STATUS-1'))->assertStatus(502)->assertExactJson(['error' => 'Checkout status unavailable.']);
    }

    public function test_durable_states_map_to_the_existing_public_contract(): void
    {
        $future = $this->statusData();
        $future['data']['reservation_expires_at'] = now('UTC')->addMinutes(10)->format('Y-m-d\\TH:i:s.v\\Z');
        $expired = $this->statusData();
        $expired['data']['reservation_expires_at'] = now('UTC')->subSecond()->format('Y-m-d\\TH:i:s.v\\Z');
        $released = $this->statusData();
        $released['data']['reservation_status'] = 'RELEASED';
        $approved = $this->statusData();
        $approved['data']['payment_status'] = 'APPROVED';
        $approved['data']['reservation_status'] = 'CONSUMED';
        $approved['data']['paid_at'] = '2026-09-21T13:05:20.166Z';
        $approved['data']['payment_last_event_at'] = '2026-09-21T13:05:20.166Z';
        $review = $this->statusData();
        $review['data']['status'] = 'PAYMENT_REVIEW_REQUIRED';
        $review['data']['payment_status'] = 'APPROVED';
        $review['data']['reservation_status'] = 'RELEASED';
        $review['data']['paid_at'] = '2026-09-21T13:05:20.166Z';
        $review['data']['payment_last_event_at'] = '2026-09-21T13:06:20.166Z';
        Http::fake([self::URL => Http::sequence()->push($future, 200)->push($expired, 200)->push($released, 200)->push($approved, 200)->push($review, 200)]);

        $this->fetchStatus($this->token('TTV-STATUS-1'))->assertOk()->assertJsonPath('checkout.reservation', 'ACTIVE');
        $this->fetchStatus($this->token('TTV-STATUS-1'))->assertOk()->assertJsonPath('checkout.reservation', 'EXPIRED');
        $this->fetchStatus($this->token('TTV-STATUS-1'))->assertOk()->assertJsonPath('checkout.reservation', 'RELEASED');
        $this->fetchStatus($this->token('TTV-STATUS-1'))->assertOk()->assertJsonPath('checkout.status', 'APPROVED')->assertJsonPath('checkout.reservation', 'CONSUMED');
        $this->fetchStatus($this->token('TTV-STATUS-1'))->assertOk()->assertJsonPath('checkout.status', 'APPROVED')->assertJsonPath('checkout.reservation', 'RELEASED');
    }

    public function test_status_updated_at_prefers_payment_event_then_durable_updated_at(): void
    {
        $withEvent = $this->statusData();
        $withEvent['data']['payment_last_event_at'] = '2026-09-21T13:10:20.166Z';
        $withoutEvent = $this->statusData();
        $withoutEvent['data']['payment_last_event_at'] = null;
        $withoutEvent['data']['updated_at'] = '2026-09-21T13:11:20.166Z';
        Http::fake([self::URL => Http::sequence()->push($withEvent, 200)->push($withoutEvent, 200)]);

        $this->fetchStatus($this->token('TTV-STATUS-1'))->assertJsonPath('checkout.statusUpdatedAt', '2026-09-21T13:10:20.166Z');
        $this->fetchStatus($this->token('TTV-STATUS-1'))->assertJsonPath('checkout.statusUpdatedAt', '2026-09-21T13:11:20.166Z');
    }

    public function test_apps_script_timeout_fails_closed(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timeout'));

        $this->fetchStatus($this->token('TTV-STATUS-1'))->assertStatus(504)->assertExactJson(['error' => 'Checkout status unavailable.']);
    }

    public function test_invalid_json_and_incomplete_apps_script_response_fail_closed(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->push('not-json', 200)
            ->push(['ok' => true, 'data' => ['order_id' => 1]], 200)]);

        $this->fetchStatus($this->token('TTV-STATUS-1'))->assertStatus(502);
        $this->fetchStatus($this->token('TTV-STATUS-1'))->assertStatus(502);
    }

    private function token(string $reference, ?int $expiration = null): string
    {
        return Crypt::encryptString(json_encode([
            'v' => 1,
            'reference' => $reference,
            'exp' => $expiration ?? now('UTC')->addDay()->timestamp,
            'nonce' => str_repeat('a', 32),
        ], JSON_THROW_ON_ERROR));
    }

    private function fetchStatus(?string $token)
    {
        return $this->withHeaders($token === null ? [] : ['X-Checkout-Status-Token' => $token])
            ->getJson('/api/payments/wompi/status');
    }

    private function statusData(): array
    {
        return ['ok' => true, 'data' => [
            'order_id' => 77,
            'reference' => 'TTV-STATUS-1',
            'status' => 'PENDING',
            'payment_status' => 'PENDING',
            'reservation_status' => 'ACTIVE',
            'reservation_expires_at' => now('UTC')->addMinutes(10)->format('Y-m-d\\TH:i:s.v\\Z'),
            'paid_at' => null,
            'payment_last_event_at' => null,
            'created_at' => '2026-09-21T13:04:20.166Z',
            'updated_at' => '2026-09-21T13:04:20.166Z',
            'revision' => 1,
        ]];
    }
}
