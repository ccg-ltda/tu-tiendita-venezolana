<?php

namespace Tests\Unit\Services;

use App\Services\AppsScriptCheckoutClient;
use App\Services\AppsScriptCheckoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AppsScriptCheckoutClientTest extends TestCase
{
    private const URL = 'https://apps-script.test/exec';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.apps_script.url' => self::URL,
            'services.apps_script.api_key' => 'apps-script-test-key',
        ]);
    }

    public function test_admin_list_orders_retries_a_connection_failure_then_succeeds(): void
    {
        $attempt = 0;
        Http::fake(function (Request $request) use (&$attempt) {
            ++$attempt;
            $this->assertSame('admin_list_orders', $request['action']);

            if ($attempt === 1) {
                throw new ConnectionException('cURL error 7: Could not connect');
            }

            return Http::response(['ok' => true, 'data' => $this->listData()], 200);
        });

        $result = app(AppsScriptCheckoutClient::class)->adminListOrders(1, 25);

        $this->assertSame($this->listData(), $result);
        $this->assertSame(2, $attempt);
    }

    public function test_candidates_retry_an_unexpected_html_404_then_succeed(): void
    {
        $attempt = 0;
        Http::fake(function (Request $request) use (&$attempt) {
            ++$attempt;
            $this->assertSame('release_expired_reservation', $request['action']);
            $this->assertSame('candidates', $request['mode']);

            if ($attempt === 1) {
                return Http::response('<html>Not Found</html>', 404, ['Content-Type' => 'text/html; charset=utf-8']);
            }

            return Http::response(['ok' => true, 'data' => ['candidates' => []]], 200);
        });

        $this->assertSame([], app(AppsScriptCheckoutClient::class)->getExpiredReservationCandidates());
        Http::assertSentCount(2);
    }

    public function test_admin_get_order_retries_a_transient_http_503_then_succeeds(): void
    {
        $attempt = 0;
        Http::fake(function (Request $request) use (&$attempt) {
            ++$attempt;
            $this->assertSame('admin_get_order', $request['action']);

            if ($attempt === 1) {
                return Http::response('temporary gateway failure', 503, ['Content-Type' => 'text/plain']);
            }

            return Http::response(['ok' => true, 'data' => ['order' => $this->detailData()]], 200);
        });

        $this->assertSame($this->detailData(), app(AppsScriptCheckoutClient::class)->adminGetOrder(42));
        Http::assertSentCount(2);
    }

    public function test_retryable_action_stops_after_three_failed_attempts(): void
    {
        $attempt = 0;
        Http::fake(function () use (&$attempt): void {
            ++$attempt;
            throw new ConnectionException('cURL error 28: timeout');
        });

        try {
            app(AppsScriptCheckoutClient::class)->adminListOrders(1, 25);
            $this->fail('Expected AppsScriptCheckoutException was not thrown.');
        } catch (AppsScriptCheckoutException $exception) {
            $this->assertSame(504, $exception->status());
        }

        $this->assertSame(3, $attempt);
    }

    public function test_non_idempotent_payment_event_is_not_retried(): void
    {
        $attempt = 0;
        Http::fake(function () use (&$attempt): void {
            ++$attempt;
            throw new ConnectionException('cURL error 7: Could not connect');
        });

        try {
            app(AppsScriptCheckoutClient::class)->recordPaymentEvent([
                'id' => 'txn-test',
                'reference' => 'TTV-TEST',
                'status' => 'APPROVED',
                'payment_method' => 'CARD',
                'amount_in_cents' => 500000,
                'currency' => 'COP',
                'event_occurred_at' => '2026-09-23T12:00:00.000Z',
            ]);
            $this->fail('Expected AppsScriptCheckoutException was not thrown.');
        } catch (AppsScriptCheckoutException $exception) {
            $this->assertSame(503, $exception->status());
        }

        $this->assertSame(1, $attempt);
    }

    private function listData(): array
    {
        return [
            'orders' => [[
                'id' => 42,
                'reference' => 'TTV-ADMIN-42',
                'status' => 'PENDING',
                'customer_name' => 'Cliente de Prueba',
                'total' => 19500,
                'created_at' => '2026-09-21T13:04:20.000Z',
            ]],
            'pagination' => ['current_page' => 1, 'per_page' => 25, 'total' => 1, 'last_page' => 1],
        ];
    }

    private function detailData(): array
    {
        return [
            'id' => 42,
            'reference' => 'TTV-ADMIN-42',
            'status' => 'PENDING',
            'customer_name' => 'Cliente de Prueba',
            'customer_email' => 'cliente@example.test',
            'customer_phone' => '3000000000',
            'customer_document' => '1000000000',
            'address' => 'Direccion de prueba',
            'extra' => null,
            'city' => 'Bogota',
            'region' => 'Bogota D.C.',
            'postal' => null,
            'total' => 19500,
            'created_at' => '2026-09-21T13:04:20.000Z',
            'items' => [[
                'id' => 9,
                'product_id' => 193,
                'product_name' => 'Producto de prueba',
                'unit_price' => 19500,
                'quantity' => 1,
            ]],
        ];
    }
}
