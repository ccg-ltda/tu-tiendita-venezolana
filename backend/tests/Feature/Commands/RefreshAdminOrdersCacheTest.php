<?php

namespace Tests\Feature\Commands;

use App\Contracts\GoogleSheetsValuesClient;
use App\Repositories\CheckoutSheetsRepository;
use App\Services\AdminOrdersCache;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class RefreshAdminOrdersCacheTest extends TestCase
{
    private AdminOrdersCache $cache;
    private object $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = app(AdminOrdersCache::class);
        $this->cache->forgetList(1, 25);
        $this->cache->forgetDetail(42);
        $this->useDirectOrders([$this->order(42, 'TTV-ADMIN-42')]);
    }

    public function test_refreshes_the_list_from_direct_sheets_without_apps_script_requests(): void
    {
        Http::fake();
        $this->artisan('orders:refresh-admin-cache --page=1 --per-page=25')->assertExitCode(0);
        $this->assertSame($this->listData(), $this->cache->list(1, 25)['data']);
        $this->assertStringNotContainsString('Cliente de Prueba', (string) Cache::get($this->cache->listCacheKey(1, 25)));
        Http::assertNothingSent();
    }

    public function test_list_refresh_does_not_prewarm_or_replace_existing_detail_cache(): void
    {
        $previousDetail = $this->detail();$this->cache->putDetail(42, $previousDetail);Http::fake();
        $this->artisan('orders:refresh-admin-cache --page=1 --per-page=25')->assertExitCode(0);
        $this->assertSame($this->listData(), $this->cache->list(1, 25)['data']);$this->assertSame($previousDetail, $this->cache->detail(42)['data']);Http::assertNothingSent();
    }

    public function test_successful_direct_refresh_replaces_the_previous_list_value(): void
    {
        $this->cache->putList(1, 25, $this->listData());$this->client->tables['Pedidos'][1][1] = 'TTV-ADMIN-UPDATED';
        $this->artisan('orders:refresh-admin-cache --page=1 --per-page=25')->assertExitCode(0);
        $next = $this->listData();$next['orders'][0]['reference'] = 'TTV-ADMIN-UPDATED';$this->assertSame($next, $this->cache->list(1, 25)['data']);
    }

    public function test_direct_read_failure_preserves_the_last_valid_list_and_makes_no_apps_script_request(): void
    {
        $previous = $this->listData();$this->cache->putList(1, 25, $previous);$this->client->throwOnRead = true;Http::fake();Log::spy();
        $this->artisan('orders:refresh-admin-cache')->assertExitCode(1);
        $this->assertSame($previous, $this->cache->list(1, 25)['data']);Http::assertNothingSent();
        Log::shouldHaveReceived('error')->with('Admin orders cache refresh failed.', \Mockery::on(fn (array $context): bool => !isset($context['reference']) && !isset($context['customer_email'])))->once();
    }

    public function test_detail_refresh_remains_explicit_and_uses_its_existing_apps_script_path(): void
    {
        config(['services.apps_script.url' => 'https://apps-script.test/exec', 'services.apps_script.api_key' => 'test-api-key']);
        Http::fake(fn () => Http::response(['ok' => true, 'data' => ['order' => $this->detail()]], 200));
        $this->artisan('orders:refresh-admin-detail 42')->assertExitCode(0);$this->assertSame($this->detail(), $this->cache->detail(42)['data']);
    }

    public function test_scheduler_prewarms_only_first_page_each_minute(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'orders:refresh-admin-cache --page=1 --per-page=25'));
        $this->assertNotNull($event);$this->assertSame('* * * * *', $event->expression);$this->assertTrue($event->withoutOverlapping);$this->assertSame(5, $event->expiresAt);
    }

    /** @param list<list<mixed>> $orders */
    private function useDirectOrders(array $orders): void
    {
        $tables = ['Pedidos' => [self::headers(), ...$orders]];
        $this->client = new class($tables) implements GoogleSheetsValuesClient {
            public bool $throwOnRead = false;
            public function __construct(public array $tables) {}
            public function getValues(string $range): array { return []; }
            public function batchGetValues(array $ranges): array { if ($this->throwOnRead) throw new \RuntimeException('Direct Sheets read failed.');return array_map(fn (string $range): array => str_ends_with($range, '1:1') ? [$this->tables['Pedidos'][0]] : $this->tables['Pedidos'], $ranges); }
            public function updateValues(string $range, array $values): array { throw new \LogicException('Read only.'); }
            public function appendValues(string $range, array $values): array { throw new \LogicException('Read only.'); }
            public function batchUpdateValues(array $data): array { throw new \LogicException('Read only.'); }
        };
        $this->app->instance(CheckoutSheetsRepository::class, new CheckoutSheetsRepository($this->client));
    }

    private function listData(): array
    { return ['orders' => [['id' => 42, 'reference' => 'TTV-ADMIN-42', 'status' => 'PENDING', 'payment_status' => 'APPROVED', 'customer_name' => 'Cliente de Prueba', 'total' => 19500, 'created_at' => '2026-09-21T13:04:20.000Z']], 'pagination' => ['current_page' => 1, 'per_page' => 25, 'total' => 1, 'last_page' => 1]]; }
    private function detail(): array
    { return ['id' => 42, 'reference' => 'TTV-ADMIN-42', 'status' => 'PENDING', 'customer_name' => 'Cliente de Prueba', 'customer_email' => 'cliente@example.test', 'customer_phone' => '3000000000', 'customer_document' => '1000000000', 'address' => 'Direccion de prueba', 'extra' => null, 'city' => 'Bogota', 'region' => 'Bogota D.C.', 'postal' => null, 'total' => 19500, 'created_at' => '2026-09-21T13:04:20.000Z', 'items' => [['id' => 9, 'product_id' => 193, 'product_name' => 'Producto historico', 'unit_price' => 19500, 'quantity' => 1]]]; }
    /** @return list<string> */
    private static function headers(): array
    { return ['order_id','reference','status','payment_status','reservation_status','reservation_expires_at','paid_at','payment_last_event_at','checkout_idempotency_key','checkout_payload_hash','release_id','release_fingerprint','released_at','customer_name','customer_email','customer_phone','customer_document','address','extra','city','region','postal','total_cop','created_at','updated_at','revision']; }
    /** @return list<mixed> */
    private function order(int $id, string $reference): array
    { return [$id,$reference,'PENDING','APPROVED','CONSUMED','2026-09-21T14:04:20.000Z','2026-09-21T13:05:00.000Z','2026-09-21T13:05:00.000Z','11111111-1111-4111-8111-111111111111',str_repeat('a',64),'','','','Cliente de Prueba','cliente@example.test','3000000000','1000000000','Direccion de prueba','','Bogota','Bogota D.C.','',19500,'2026-09-21T13:04:20.000Z','2026-09-21T13:04:20.000Z',1]; }
}
