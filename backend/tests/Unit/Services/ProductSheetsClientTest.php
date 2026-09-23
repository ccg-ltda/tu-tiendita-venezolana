<?php

namespace Tests\Unit\Services;

use App\Services\ProductSheetsClient;
use App\Services\ProductSheetsException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ProductSheetsClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.apps_script.url' => 'https://apps-script.test/exec',
            'services.apps_script.api_key' => 'test-api-key',
        ]);
    }

    public function test_it_fetches_and_normalizes_fresh_products_without_writing_cache(): void
    {
        Log::spy();
        Http::fake(['https://apps-script.test/exec' => Http::response([
            'ok' => true,
            'data' => [$this->sheetProduct()],
        ])]);

        $products = app(ProductSheetsClient::class)->fetchFreshProducts();

        $this->assertSame([$this->sheetProduct()], $products);
        Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
            && $request->data() === ['action' => 'list_products', 'api_key' => 'test-api-key']);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_each_fresh_fetch_makes_one_upstream_request(): void
    {
        Http::fake(['https://apps-script.test/exec' => Http::response([
            'ok' => true,
            'data' => [$this->sheetProduct()],
        ])]);

        app(ProductSheetsClient::class)->fetchFreshProducts();
        app(ProductSheetsClient::class)->fetchFreshProducts();

        Http::assertSentCount(2);
    }

    public function test_list_read_retries_a_transient_upstream_failure(): void
    {
        Http::fake(['https://apps-script.test/exec' => Http::sequence()
            ->push([], 503)
            ->push(['ok' => true, 'data' => [$this->sheetProduct()]], 200)]);

        $this->assertSame([$this->sheetProduct()], app(ProductSheetsClient::class)->fetchFreshProducts());
        Http::assertSentCount(2);
    }

    public function test_it_rejects_missing_configuration_without_making_a_request(): void
    {
        config(['services.apps_script.url' => null]);
        Http::fake();

        $this->assertFailureStatus(503);
        Http::assertNothingSent();
    }

    public function test_it_rejects_non_successful_and_invalid_upstream_payloads(): void
    {
        foreach ([
            Http::response('sensitive-response-body test-api-key', 502),
            Http::response('{"ok":', 200, ['Content-Type' => 'application/json']),
            Http::response(['ok' => false, 'data' => []]),
            Http::response(['ok' => true, 'data' => 'invalid']),
        ] as $response) {
            Log::spy();
            Http::fake(['https://apps-script.test/exec' => $response]);
            $this->assertFailureStatus(502);
        }
    }

    public function test_it_rejects_invalid_or_duplicate_products(): void
    {
        $invalid = $this->sheetProduct();
        $invalid['revision'] = 0;

        foreach ([[$invalid], [$this->sheetProduct(), $this->sheetProduct()]] as $products) {
            Http::fake(['https://apps-script.test/exec' => Http::response(['ok' => true, 'data' => $products])]);
            $this->assertFailureStatus(502);
        }
    }

    public function test_it_classifies_connection_failures_without_logging_secrets(): void
    {
        Log::spy();
        Http::fake(['https://apps-script.test/exec' => Http::failedConnection('connection failed: https://apps-script.test/exec?api_key=test-api-key')]);

        $this->assertFailureStatus(503);
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            return $message === 'Product Sheets request failed.'
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'test-api-key');
        });
    }

    public function test_timeout_detection_covers_curl_timeout_messages(): void
    {
        $method = new \ReflectionMethod(ProductSheetsClient::class, 'isTimeout');

        $this->assertTrue($method->invoke(app(ProductSheetsClient::class), new ConnectionException('cURL error 28: timed out')));
    }

    /** @return array<string, int|bool|string> */
    private function sheetProduct(): array
    {
        return [
            'product_id' => 1,
            'category' => 'Despensa',
            'subcategory' => 'Harinas',
            'name' => 'Harina PAN',
            'presentation' => '1 kg',
            'price_cop' => 12000,
            'inventory' => 8,
            'active' => true,
            'image_path' => 'assets/products/01.jpg',
            'legacy_img' => '01',
            'created_at' => '2026-09-18T00:00:00Z',
            'updated_at' => '2026-09-18T00:00:00Z',
            'revision' => 1,
        ];
    }

    private function assertFailureStatus(int $expectedStatus): void
    {
        try {
            app(ProductSheetsClient::class)->fetchFreshProducts();
            $this->fail('Expected ProductSheetsException.');
        } catch (ProductSheetsException $exception) {
            $this->assertSame($expectedStatus, $exception->status());
        }
    }
}
