<?php

namespace Tests\Feature\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WompiPaymentStatusTest extends TestCase
{
    private const APPS_URL = 'https://apps-script.test/exec';

    private const WOMPI_URL = 'https://sandbox.wompi.co/v1';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.apps_script.url' => self::APPS_URL,
            'services.apps_script.api_key' => 'apps-script-test-key',
            'services.wompi.environment' => 'sandbox',
            'services.wompi.base_url' => self::WOMPI_URL,
            'services.wompi.private_key' => 'prv_test_private-key',
        ]);
    }

    public function test_approved_status_comes_directly_from_wompi_without_waiting_for_apps_script(): void
    {
        Http::fake([
            self::WOMPI_URL.'/transactions/transaction-1' => Http::response(['data' => $this->transaction('APPROVED')], 200),
            self::APPS_URL => fn () => throw new ConnectionException('Apps Script timeout'),
        ]);

        $this->fetchStatus($this->token())->assertOk()->assertExactJson(['checkout' => ['status' => 'APPROVED']]);
        $this->assertWompiLookupSent();
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => $this->requestAction($request) === 'record_payment_event');
    }

    public function test_pending_status_comes_directly_from_wompi_without_apps_script(): void
    {
        Http::fake([self::WOMPI_URL.'/transactions/transaction-1' => Http::response(['data' => $this->transaction('PENDING')], 200)]);

        $this->fetchStatus($this->token())->assertOk()->assertExactJson(['checkout' => ['status' => 'PENDING']]);
        $this->assertWompiLookupSent();
        Http::assertSentCount(1);
    }

    /** @dataProvider finalStatuses */
    public function test_every_final_wompi_status_is_returned(string $status): void
    {
        Http::fake([
            self::WOMPI_URL.'/transactions/transaction-1' => Http::response(['data' => $this->transaction($status)], 200),
            self::APPS_URL => fn () => throw new ConnectionException('Apps Script timeout'),
        ]);

        $this->fetchStatus($this->token())->assertOk()->assertExactJson(['checkout' => ['status' => $status]]);
        Http::assertSentCount(1);
    }

    public static function finalStatuses(): array
    {
        return [['DECLINED'], ['VOIDED'], ['ERROR']];
    }

    public function test_temporary_wompi_failure_is_recoverable_and_never_reported_as_pending(): void
    {
        Http::fake([self::WOMPI_URL.'/transactions/transaction-1' => fn () => throw new ConnectionException('timeout')]);

        $this->fetchStatus($this->token())->assertStatus(503)->assertExactJson(['error' => 'Payment status temporarily unavailable.']);
        Http::assertNothingSent();
    }

    public function test_apps_script_timeout_is_not_called_or_allowed_to_delay_an_approved_payment(): void
    {
        Http::fake([
            self::WOMPI_URL.'/transactions/transaction-1' => Http::response(['data' => $this->transaction('APPROVED')], 200),
            self::APPS_URL => fn () => throw new ConnectionException('timeout'),
        ]);

        $this->fetchStatus($this->token())->assertOk()->assertExactJson(['checkout' => ['status' => 'APPROVED']]);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => $this->requestAction($request) === 'record_payment_event');
    }

    public function test_status_check_only_looks_up_the_existing_transaction_and_never_prepares_a_checkout(): void
    {
        Http::fake([self::WOMPI_URL.'/transactions/transaction-1' => Http::response(['data' => $this->transaction('PENDING')], 200)]);

        $this->fetchStatus($this->token())->assertOk();

        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => $this->requestAction($request) === 'prepare_checkout');
    }

    public function test_transaction_must_belong_to_the_sealed_checkout_reference_and_amount(): void
    {
        Http::fake([self::WOMPI_URL.'/transactions/transaction-1' => Http::response(['data' => $this->transaction('APPROVED', reference: 'TTV-OTHER')], 200)]);

        $this->fetchStatus($this->token())->assertStatus(502)->assertExactJson(['error' => 'Payment status temporarily unavailable.']);
        Http::assertSentCount(1);
    }

    public function test_absent_tampered_or_structurally_invalid_tokens_fail_closed_without_http(): void
    {
        Http::fake();
        $tokens = [null, 'not-a-token', Crypt::encryptString('not-json'), Crypt::encryptString(json_encode(['v' => 1, 'reference' => 'TTV-STATUS-1', 'exp' => now()->addHour()->timestamp, 'nonce' => str_repeat('a', 32)], JSON_THROW_ON_ERROR)), $this->token(expiration: now()->subSecond()->timestamp)];

        foreach ($tokens as $token) {
            $this->fetchStatus($token)->assertNotFound();
        }

        Http::assertNothingSent();
    }

    private function fetchStatus(?string $token)
    {
        $headers = ['X-Wompi-Transaction-Id' => 'transaction-1'];
        if ($token !== null) {
            $headers['X-Checkout-Status-Token'] = $token;
        }

        return $this->withHeaders($headers)->getJson('/api/payments/wompi/status');
    }

    private function token(?int $expiration = null): string
    {
        return Crypt::encryptString(json_encode(['v' => 1, 'reference' => 'TTV-STATUS-1', 'amount_in_cents' => 1950000, 'currency' => 'COP', 'exp' => $expiration ?? now('UTC')->addDay()->timestamp, 'nonce' => str_repeat('a', 32)], JSON_THROW_ON_ERROR));
    }

    private function transaction(string $status, string $reference = 'TTV-STATUS-1'): array
    {
        return ['id' => 'transaction-1', 'reference' => $reference, 'status' => $status, 'amount_in_cents' => 1950000, 'currency' => 'COP', 'payment_method_type' => 'CARD'];
    }

    private function assertWompiLookupSent(): void
    {
        Http::assertSent(fn ($request): bool => $request->url() === self::WOMPI_URL.'/transactions/transaction-1' && $request->hasHeader('Authorization', 'Bearer prv_test_private-key'));
    }

    private function requestAction($request): ?string
    {
        $body = json_decode($request->body(), true);

        return is_array($body) ? ($body['action'] ?? null) : null;
    }
}
