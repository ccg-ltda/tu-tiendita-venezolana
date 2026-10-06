<?php

namespace Tests\Feature\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class WompiPaymentPrepareTest extends TestCase
{
    private const URL = 'https://apps-script.test/exec';

    protected function setUp(): void
    {
        parent::setUp();
        config(['checkout.writer_backend' => 'apps_script', 'services.wompi.public_key' => 'pub_test_public_key', 'services.wompi.integrity_secret' => 'integrity_test_secret', 'services.apps_script.url' => self::URL, 'services.apps_script.api_key' => 'apps-script-test-key']);
        RateLimiter::clear('wompi-prepare:127.0.0.1');
        Cache::flush();
    }

    public function test_valid_prepare_translates_consolidates_canonicalizes_and_uses_sheets_data(): void
    {
        Http::fake([self::URL => Http::response($this->success(), 200)]);
        $response = $this->prepare([['id' => 193, 'qty' => 1], ['id' => '193', 'qty' => '2']])->assertCreated();
        $response->assertJsonPath('order.id', 77)->assertJsonPath('order.reference', 'TTV-SHEETS-77')->assertJsonPath('order.total', 19500)->assertJsonPath('payment.amountInCents', 1950000);
        Http::assertSent(function ($request): bool {
            $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

            return $body['action'] === 'prepare_checkout' && $body['api_key'] === 'apps-script-test-key'
                && $body['idempotency_key'] === $this->key() && $body['items'] === [['product_id' => 193, 'quantity' => 3]]
                && $body['customer']['phone'] === '+573000000000' && $body['customer']['extra'] === 'Apartamento 2';
        });
    }

    public function test_release_canonical_hash_matches_apps_script_exactly(): void
    {
        Http::fake([self::URL => Http::response($this->success(), 200)]);
        $customer = ['name' => 'Prueba Release', 'email' => 'release@test.local', 'phone' => '3000000000', 'document' => '1000000000', 'address' => 'Dirección prueba', 'extra' => '', 'city' => 'Bogotá', 'region' => 'Bogotá D.C.', 'postal' => '110111'];
        $this->withHeader('Idempotency-Key', $this->key())->postJson('/api/payments/wompi/prepare', ['customer' => $customer, 'items' => [['id' => 193, 'qty' => 1]]])->assertCreated();
        Http::assertSent(fn ($request): bool => json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR)['payload_hash'] === 'd305507074f079f9d92abba5fe1b26d704cd32a90f9e7a059f245d706e7684eb');
    }

    public function test_widget_signature_reference_total_and_autonomous_status_token_are_valid(): void
    {
        Http::fake([self::URL => Http::response($this->success(), 200)]);
        $response = $this->prepare([['id' => 193, 'qty' => 1]])->assertCreated();
        $expiration = $response->json('payment.expirationTime');
        $this->assertSame(hash('sha256', 'TTV-SHEETS-77'.'1950000COP'.$expiration.'integrity_test_secret'), $response->json('payment.integritySignature'));
        $decoded = json_decode(Crypt::decryptString($response->json('checkout.statusToken')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $decoded['v']);
        $this->assertSame('TTV-SHEETS-77', $decoded['reference']);
        $this->assertIsInt($decoded['exp']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $decoded['nonce']);
    }

    public function test_wompi_uses_the_discounted_reservation_total_and_never_forwards_client_prices(): void
    {
        Http::fake([self::URL => Http::response($this->success(), 200)]);
        $payload = $this->payload([['id' => 193, 'qty' => 1, 'price' => 1, 'effective_price_cop' => 1, 'discount_cop' => 999999]]);
        $response = $this->withHeader('Idempotency-Key', $this->key())->postJson('/api/payments/wompi/prepare', $payload)->assertCreated();

        $this->assertSame(1950000, $response->json('payment.amountInCents'));
        $expiration = $response->json('payment.expirationTime');
        $this->assertSame(hash('sha256', 'TTV-SHEETS-77'.'1950000COP'.$expiration.'integrity_test_secret'), $response->json('payment.integritySignature'));
        Http::assertSent(function ($request): bool {
            $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

            return $body['items'] === [['product_id' => 193, 'quantity' => 1]];
        });
    }

    public function test_replayed_apps_script_prepare_preserves_widget_contract(): void
    {
        $data = $this->success();
        $data['data']['idempotency_replayed'] = true;
        Http::fake([self::URL => Http::response($data, 200)]);
        $this->prepare([['id' => 193, 'qty' => 1]])->assertOk()->assertJsonPath('order.reference', 'TTV-SHEETS-77');
    }

    public function test_remote_business_errors_are_controlled(): void
    {
        $cases = ['INVALID_REQUEST' => 400, 'INSUFFICIENT_STOCK' => 409, 'PRODUCT_NOT_FOUND' => 409, 'INVALID_PRODUCT_PRICE' => 409, 'IDEMPOTENCY_CONFLICT' => 409, 'RESERVATION_EXPIRED' => 409, 'LOCK_TIMEOUT' => 503, 'CONSISTENCY_UNCERTAIN' => 502];
        $responses = Http::sequence();
        foreach (array_keys($cases) as $code) {
            $responses->push(['ok' => false, 'error' => ['code' => $code]], 200);
        }
        Http::fake([self::URL => $responses]);
        foreach ($cases as $code => $status) {
            RateLimiter::clear('wompi-prepare:127.0.0.1');
            $this->prepare([['id' => 193, 'qty' => 1]])->assertStatus($status);
        }
    }

    public function test_timeout_fails_closed(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timeout'));
        $this->prepare([['id' => 193, 'qty' => 1]])->assertStatus(504);
    }

    public function test_ambiguous_prepare_failure_marks_the_same_key_and_sends_recovery_only_on_its_retry(): void
    {
        $attempt = 0;
        Http::fake(function () use (&$attempt) {
            $attempt++;
            if ($attempt === 1) {
                throw new ConnectionException('cURL error 28: timeout');
            }

            return Http::response($this->success(), 200);
        });

        $this->prepare([['id' => 193, 'qty' => 1]])->assertStatus(504);
        $this->prepare([['id' => 193, 'qty' => 1]])->assertCreated();
        Http::assertSent(function ($request): bool {
            $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

            return $body['action'] === 'prepare_checkout' && $body['recover_after_ambiguous_prepare'] === true;
        });
    }

    public function test_expired_ambiguous_recovery_clears_its_state_without_automatic_retry(): void
    {
        $attempt = 0;
        Http::fake(function () use (&$attempt) {
            ++$attempt;
            if ($attempt === 1) {
                throw new ConnectionException('cURL error 28: timeout');
            }
            if ($attempt === 2) {
                return Http::response(['ok' => false, 'error' => ['code' => 'RESERVATION_EXPIRED']], 200);
            }

            return Http::response($this->success(), 200);
        });
        $key = $this->key();
        $stateKey = 'wompi:prepare-state:'.hash('sha256', $key);

        $this->prepare([['id' => 193, 'qty' => 1]], $key)->assertStatus(504);
        $this->prepare([['id' => 193, 'qty' => 1]], $key)
            ->assertConflict()
            ->assertJsonPath('code', 'RESERVATION_EXPIRED')
            ->assertJsonPath('error', 'La reserva anterior venció. Vuelve a continuar al pago para generar una nueva.');

        $this->assertSame(2, $attempt);
        $this->assertNull(Cache::get($stateKey));

        $newKey = '22222222-2222-4222-8222-222222222222';
        $this->prepare([['id' => 193, 'qty' => 1]], $newKey)->assertCreated();
        $this->assertSame(3, $attempt);
        Http::assertSent(function ($request) use ($newKey): bool {
            $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

            return $body['idempotency_key'] === $newKey
                && $body['recover_after_ambiguous_prepare'] === false
                && $body['customer']['email'] === 'cliente@example.test'
                && $body['items'] === [['product_id' => 193, 'quantity' => 1]];
        });
    }

    public function test_successful_prepare_is_never_later_marked_recoverable_by_an_accidental_failure(): void
    {
        $attempt = 0;
        Http::fake(function () use (&$attempt) {
            $attempt++;
            if ($attempt === 2) {
                throw new ConnectionException('cURL error 28: timeout');
            }

            return Http::response($this->success(), 200);
        });

        $this->prepare([['id' => 193, 'qty' => 1]])->assertCreated();
        $this->prepare([['id' => 193, 'qty' => 1]])->assertStatus(504);
        $this->prepare([['id' => 193, 'qty' => 1]])->assertCreated();
        $request = Http::recorded()->last()[0];
        $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($body['recover_after_ambiguous_prepare']);
    }

    public function test_ambiguous_marker_is_bound_to_the_original_payload_hash(): void
    {
        $attempt = 0;
        Http::fake(function () use (&$attempt) {
            $attempt++;
            if ($attempt === 1) {
                throw new ConnectionException('cURL error 28: timeout');
            }

            if ($attempt === 2) {
                return Http::response(['ok' => false, 'error' => ['code' => 'IDEMPOTENCY_CONFLICT']], 200);
            }

            return Http::response($this->success(), 200);
        });
        $this->prepare([['id' => 193, 'qty' => 1]])->assertStatus(504);
        $this->prepare([['id' => 193, 'qty' => 2]])->assertConflict();
        Http::assertSent(function ($request): bool {
            $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

            return $body['recover_after_ambiguous_prepare'] === false;
        });
        $this->prepare([['id' => 193, 'qty' => 1]])->assertCreated();
        $body = json_decode(Http::recorded()->last()[0]->body(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($body['recover_after_ambiguous_prepare']);
    }

    public function test_frontend_cannot_enable_the_internal_recovery_flag(): void
    {
        Http::fake([self::URL => Http::response($this->success(), 200)]);
        $payload = $this->payload([['id' => 193, 'qty' => 1]]);
        $payload['recover_after_ambiguous_prepare'] = true;
        $this->withHeader('Idempotency-Key', $this->key())->postJson('/api/payments/wompi/prepare', $payload)->assertCreated();
        Http::assertSent(function ($request): bool {
            $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

            return $body['recover_after_ambiguous_prepare'] === false;
        });
    }

    public function test_non_json_incomplete_and_overflow_fail_closed(): void
    {
        $overflow = $this->success();
        $overflow['data']['total_cop'] = PHP_INT_MAX;
        Http::fake([self::URL => Http::sequence()
            ->push('not-json', 200)
            ->push(['ok' => true, 'data' => ['order_id' => 77]], 200)
            ->push($overflow, 200)]);
        $this->prepare([['id' => 193, 'qty' => 1]])->assertStatus(502);
        $this->prepare([['id' => 193, 'qty' => 1]])->assertStatus(502);
        $this->prepare([['id' => 193, 'qty' => 1]])->assertStatus(502);
    }

    public function test_invalid_input_and_missing_wompi_config_never_call_apps_script(): void
    {
        Http::fake();
        $payload = $this->payload([['id' => 193, 'qty' => 0]]);
        $this->withHeader('Idempotency-Key', $this->key())->postJson('/api/payments/wompi/prepare', $payload)->assertBadRequest();
        config(['services.wompi.public_key' => null]);
        $this->prepare([['id' => 193, 'qty' => 1]])->assertServiceUnavailable();
        Http::assertNothingSent();
    }

    public function test_api_key_and_wompi_secrets_never_appear_in_response(): void
    {
        Http::fake([self::URL => Http::response($this->success(), 200)]);
        $body = $this->prepare([['id' => 193, 'qty' => 1]])->assertCreated()->getContent();
        $this->assertStringNotContainsString('apps-script-test-key', $body);
        $this->assertStringNotContainsString('integrity_test_secret', $body);
    }

    private function prepare(array $items, ?string $key = null)
    {
        return $this->withHeader('Idempotency-Key', $key ?? $this->key())->postJson('/api/payments/wompi/prepare', $this->payload($items));
    }

    private function key(): string
    {
        return '11111111-1111-4111-8111-111111111111';
    }

    private function payload(array $items): array
    {
        return ['customer' => ['name' => 'Cliente de Prueba', 'email' => 'cliente@example.test', 'phone' => '3000000000', 'document' => '1000000000', 'address' => 'Calle de Prueba 1', 'extra' => 'Apartamento 2', 'city' => 'Bogotá', 'region' => 'Bogotá D.C.', 'postal' => '110111'], 'items' => $items];
    }

    private function success(): array
    {
        return ['ok' => true, 'data' => ['order_id' => 77, 'reference' => 'TTV-SHEETS-77', 'status' => 'PENDING', 'payment_status' => 'PENDING', 'reservation_status' => 'ACTIVE', 'reservation_expires_at' => '2026-09-21T13:14:20.166Z', 'total_cop' => 19500, 'created_at' => '2026-09-21T13:04:20.166Z', 'revision' => 1, 'idempotency_replayed' => false]];
    }
}
