<?php

namespace Tests\Unit\Services;

use App\Services\AdminOrdersCache;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AdminOrdersCacheTest extends TestCase
{
    private AdminOrdersCache $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = app(AdminOrdersCache::class);
        $this->cache->forgetList(1, 25);
        $this->cache->forgetDetail(42);
    }

    public function test_cache_payload_is_encrypted_and_pii_is_not_present_in_raw_cache_value(): void
    {
        $detail = $this->detail();
        $this->cache->putDetail(42, $detail);

        $raw = Cache::get($this->cache->detailCacheKey(42));
        $this->assertIsString($raw);
        $this->assertStringNotContainsString($detail['customer_email'], $raw);
        $this->assertStringNotContainsString($detail['customer_document'], $raw);
        $this->assertSame($detail, $this->cache->detail(42)['data']);
    }

    public function test_invalid_encrypted_payload_is_discarded_without_returning_data(): void
    {
        Cache::put($this->cache->detailCacheKey(42), 'invalid', now()->addMinutes(5));

        $this->assertNull($this->cache->detail(42));
        $this->assertNull(Cache::get($this->cache->detailCacheKey(42)));
    }

    public function test_stale_marker_preserves_fallback_but_not_freshness(): void
    {
        $this->cache->putList(1, 25, $this->listData());
        $this->cache->markListStale(1, 25);

        $entry = $this->cache->list(1, 25);
        $this->assertNotNull($entry);
        $this->assertTrue($entry['stale']);
        $this->assertSame($this->listData(), $entry['data']);
    }

    public function test_list_remains_servable_as_stale_between_the_fresh_and_fallback_windows(): void
    {
        $this->cache->putList(1, 25, $this->listData());
        $this->travel(AdminOrdersCache::FRESH_FOR_SECONDS + 1)->seconds();

        $entry = $this->cache->list(1, 25);

        $this->assertNotNull($entry);
        $this->assertTrue($entry['stale']);
        $this->assertSame($this->listData(), $entry['data']);
    }

    public function test_entries_expire_after_the_bounded_fallback_window(): void
    {
        $this->cache->putList(1, 25, $this->listData());
        $this->travel(AdminOrdersCache::FALLBACK_FOR_SECONDS + 1)->seconds();

        $this->assertNull($this->cache->list(1, 25));
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
