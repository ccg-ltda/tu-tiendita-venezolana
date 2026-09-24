<?php

namespace Tests\Feature\Payments;

use App\Support\Payments\WompiTransactionClient;
use App\Support\Payments\WompiTransactionException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WompiTransactionClientTest extends TestCase
{
    private WompiTransactionClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new WompiTransactionClient;
        config([
            'services.wompi.environment' => 'sandbox',
            'services.wompi.base_url' => 'https://sandbox.wompi.co/v1',
            'services.wompi.private_key' => 'prv_test_private_key_for_tests',
        ]);
    }

    public function test_it_gets_a_normalized_sandbox_transaction_using_private_bearer_authentication(): void
    {
        Http::fake([
            'https://sandbox.wompi.co/v1/transactions/transaction-1' => Http::response([
                'data' => $this->transaction(),
            ]),
        ]);

        $transaction = $this->client->fetch('transaction-1');

        $this->assertSame($this->transaction(), $transaction);
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://sandbox.wompi.co/v1/transactions/transaction-1'
                && $request->hasHeader('Accept', 'application/json')
                && $request->hasHeader('Authorization', 'Bearer prv_test_private_key_for_tests');
        });
    }

    public function test_it_accepts_each_supported_wompi_status(): void
    {
        $statuses = ['APPROVED', 'PENDING', 'DECLINED', 'VOIDED', 'ERROR'];
        $responses = [];

        foreach ($statuses as $status) {
            $responses['https://sandbox.wompi.co/v1/transactions/transaction-'.$status] = Http::response([
                'data' => $this->transaction(status: $status),
            ]);
        }

        Http::fake($responses);

        foreach ($statuses as $status) {
            $this->assertSame($status, $this->client->fetch('transaction-'.$status)['status']);
        }
    }

    public function test_it_rejects_invalid_transaction_ids_without_http_requests(): void
    {
        foreach (['', 'https://evil.test/transactions/1', 'transaction/1', str_repeat('a', 256)] as $id) {
            Http::fake();

            $this->assertThrowsWompi(fn () => $this->client->fetch($id));
            Http::assertNothingSent();
        }
    }

    public function test_it_rejects_missing_or_invalid_configuration_without_http_requests(): void
    {
        $invalidConfigurations = [
            ['services.wompi.private_key' => null],
            ['services.wompi.base_url' => null],
            ['services.wompi.environment' => null],
            ['services.wompi.environment' => 'unknown'],
            ['services.wompi.private_key' => 'prv_prod_wrong_environment'],
            [
                'services.wompi.environment' => 'production',
                'services.wompi.base_url' => 'https://production.wompi.co/v1',
                'services.wompi.private_key' => 'prv_test_wrong_environment',
            ],
            ['services.wompi.base_url' => 'https://sandbox.wompi.co/v1/other'],
            [
                'services.wompi.environment' => 'production',
                'services.wompi.base_url' => 'https://sandbox.wompi.co/v1',
                'services.wompi.private_key' => 'prv_prod_private_key_for_tests',
            ],
        ];

        foreach ($invalidConfigurations as $configuration) {
            config([
                'services.wompi.environment' => 'sandbox',
                'services.wompi.base_url' => 'https://sandbox.wompi.co/v1',
                'services.wompi.private_key' => 'prv_test_private_key_for_tests',
            ]);
            config($configuration);
            Http::fake();

            $this->assertThrowsWompi(fn () => $this->client->fetch('transaction-1'));
            Http::assertNothingSent();
        }
    }

    public function test_it_handles_http_failures_as_unverifiable_not_declined(): void
    {
        foreach ([401, 403, 404, 429, 500, 503] as $status) {
            Http::fake(['*' => Http::response(['error' => 'not relevant'], $status)]);

            try {
                $this->client->fetch('transaction-1');
                $this->fail('Expected an internal Wompi lookup failure.');
            } catch (WompiTransactionException $exception) {
                $this->assertStringNotContainsString('DECLINED', $exception->getMessage());
            }
        }
    }

    public function test_it_handles_connection_failures_as_unverifiable(): void
    {
        Http::fake(function (): void {
            throw new ConnectionException('Connection failed.');
        });

        $this->assertThrowsWompi(fn () => $this->client->fetch('transaction-1'));
    }

    public function test_it_rejects_invalid_wompi_response_shapes(): void
    {
        $invalidResponses = [
            'invalid json',
            [],
            ['data' => ['reference' => 'TTV-REFERENCE', 'status' => 'APPROVED', 'amount_in_cents' => 100000, 'currency' => 'COP']],
            ['data' => ['id' => 'transaction-1', 'status' => 'APPROVED', 'amount_in_cents' => 100000, 'currency' => 'COP']],
            ['data' => ['id' => 'transaction-1', 'reference' => 'TTV-REFERENCE', 'status' => 'PROCESSING', 'amount_in_cents' => 100000, 'currency' => 'COP']],
            ['data' => ['id' => 'transaction-1', 'reference' => 'TTV-REFERENCE', 'status' => 'APPROVED', 'amount_in_cents' => '100000', 'currency' => 'COP']],
            ['data' => ['id' => 'transaction-1', 'reference' => 'TTV-REFERENCE', 'status' => 'APPROVED', 'amount_in_cents' => 100000, 'currency' => 'USD']],
        ];

        foreach ($invalidResponses as $response) {
            Http::fake(['*' => Http::response($response)]);

            $this->assertThrowsWompi(fn () => $this->client->fetch('transaction-1'));
        }
    }

    /** @return array{id: string, reference: string, status: string, amount_in_cents: int, currency: string, payment_method_type: string} */
    private function transaction(
        string $status = 'APPROVED',
        string $reference = 'TTV-REFERENCE',
        int $amount = 100000,
        string $currency = 'COP',
    ): array {
        return [
            'id' => 'transaction-1',
            'reference' => $reference,
            'status' => $status,
            'amount_in_cents' => $amount,
            'currency' => $currency,
            'payment_method_type' => 'CARD',
        ];
    }

    private function assertThrowsWompi(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected WompiTransactionException.');
        } catch (WompiTransactionException) {
            $this->addToAssertionCount(1);
        }
    }
}
