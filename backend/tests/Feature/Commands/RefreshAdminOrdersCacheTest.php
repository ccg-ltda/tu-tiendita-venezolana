<?php

namespace Tests\Feature\Commands;

use App\Services\AdminOrdersCache;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RefreshAdminOrdersCacheTest extends TestCase
{
    private AdminOrdersCache $cache;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.apps_script.url', 'https://apps-script.test/exec');
        config()->set('services.apps_script.api_key', 'test-api-key');
        $this->cache = app(AdminOrdersCache::class);
        $this->cache->forgetList(1, 25);
        $this->cache->forgetDetail(42);
    }

    public function test_refreshes_a_list_page_without_prewarming_each_detail_and_keeps_the_raw_value_encrypted(): void
    {
        Http::fake(function (Request $request) {
            $this->assertSame('admin_list_orders', $request['action']);
            return Http::response(['ok' => true, 'data' => $this->listData()], 200);
        });

        $this->artisan('orders:refresh-admin-cache --page=1 --per-page=25')->assertExitCode(0);
        $this->assertSame($this->listData(), $this->cache->list(1, 25)['data']);
        $this->assertStringNotContainsString('Cliente de Prueba', (string) Cache::get($this->cache->listCacheKey(1, 25)));
        Http::assertSentCount(1);
    }

    public function test_list_refresh_keeps_a_previously_cached_detail_when_no_detail_prewarm_is_run(): void
    {
        $previousDetail = $this->detail();
        $this->cache->putDetail(42, $previousDetail);
        Http::fake(function (Request $request) {
            $this->assertSame('admin_list_orders', $request['action']);

            return Http::response(['ok' => true, 'data' => $this->listData()], 200);
        });

        $this->artisan('orders:refresh-admin-cache --page=1 --per-page=25')->assertExitCode(0);

        $this->assertSame($this->listData(), $this->cache->list(1, 25)['data']);
        $this->assertSame($previousDetail, $this->cache->detail(42)['data']);
        Http::assertSentCount(1);
    }

    public function test_successful_refresh_replaces_the_previous_list_value(): void
    {
        $previous = $this->listData();
        $next = $this->listData();
        $next['orders'][0]['reference'] = 'TTV-ADMIN-UPDATED';
        $this->cache->putList(1, 25, $previous);
        Http::fake(fn () => Http::response(['ok' => true, 'data' => $next], 200));

        $this->artisan('orders:refresh-admin-cache --page=1 --per-page=25')->assertExitCode(0);

        $this->assertSame($next, $this->cache->list(1, 25)['data']);
    }

    public function test_upstream_timeout_preserves_last_valid_list_and_sanitizes_logs(): void
    {
        $previous = $this->listData();
        $this->cache->putList(1, 25, $previous);
        Log::spy();
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->artisan('orders:refresh-admin-cache')->assertExitCode(1);
        $this->assertSame($previous, $this->cache->list(1, 25)['data']);
        Log::shouldHaveReceived('warning')->with('Admin orders cache refresh failed.', \Mockery::on(fn (array $context): bool => ! isset($context['reference']) && ! isset($context['customer_email']) && ! isset($context['items'])))->once();
    }

    public function test_upstream_502_preserves_the_last_valid_list(): void
    {
        $previous = $this->listData();
        $this->cache->putList(1, 25, $previous);
        Http::fake(Http::response('bad gateway', 502));

        $this->artisan('orders:refresh-admin-cache')->assertExitCode(1);
        $this->assertSame($previous, $this->cache->list(1, 25)['data']);
    }

    public function test_upstream_connection_failure_preserves_the_last_valid_list(): void
    {
        $previous = $this->listData();
        $this->cache->putList(1, 25, $previous);
        Http::fake(fn () => throw new ConnectionException('connection refused'));

        $this->artisan('orders:refresh-admin-cache')->assertExitCode(1);
        $this->assertSame($previous, $this->cache->list(1, 25)['data']);
    }

    public function test_detail_refresh_is_explicit_and_never_part_of_a_get_request(): void
    {
        Http::fake(fn (Request $request) => Http::response(['ok' => true, 'data' => ['order' => $this->detail()]], 200));

        $this->artisan('orders:refresh-admin-detail 42')->assertExitCode(0);
        $this->assertSame($this->detail(), $this->cache->detail(42)['data']);
    }

    public function test_scheduler_prewarms_only_first_page_each_minute(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'orders:refresh-admin-cache --page=1 --per-page=25'));
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(5, $event->expiresAt);
    }

    private function listData(): array
    {
        return ['orders' => [['id' => 42, 'reference' => 'TTV-ADMIN-42', 'status' => 'PENDING', 'customer_name' => 'Cliente de Prueba', 'total' => 19500, 'created_at' => '2026-09-21T13:04:20.000Z']], 'pagination' => ['current_page' => 1, 'per_page' => 25, 'total' => 1, 'last_page' => 1]];
    }

    private function detail(): array
    {
        return ['id' => 42, 'reference' => 'TTV-ADMIN-42', 'status' => 'PENDING', 'customer_name' => 'Cliente de Prueba', 'customer_email' => 'cliente@example.test', 'customer_phone' => '3000000000', 'customer_document' => '1000000000', 'address' => 'Direccion de prueba', 'extra' => null, 'city' => 'Bogota', 'region' => 'Bogota D.C.', 'postal' => null, 'total' => 19500, 'created_at' => '2026-09-21T13:04:20.000Z', 'items' => [['id' => 9, 'product_id' => 193, 'product_name' => 'Producto historico', 'unit_price' => 19500, 'quantity' => 1]]];
    }
}
