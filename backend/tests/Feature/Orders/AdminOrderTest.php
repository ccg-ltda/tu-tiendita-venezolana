<?php

namespace Tests\Feature\Orders;

use App\Services\AdminOrdersCache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminOrderTest extends TestCase
{
    private AdminOrdersCache $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = app(AdminOrdersCache::class);
        $this->cache->forgetList(1, 25);
        $this->cache->forgetDetail(42);
    }

    public function test_unauthenticated_requests_are_rejected_without_upstream(): void
    {
        $this->getJson('/api/admin/orders')->assertUnauthorized();
        $this->getJson('/api/admin/orders/42')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_fresh_list_is_served_without_upstream(): void
    {
        $data = $this->listData();
        $this->cache->putList(1, 25, $data);
        $this->withSession(['admin_authenticated' => true])->getJson('/api/admin/orders')->assertOk()->assertExactJson($data);
        Http::assertNothingSent();
    }

    public function test_stale_list_is_served_without_upstream(): void
    {
        $data = $this->listData();
        $this->cache->putList(1, 25, $data);
        $this->travel(61)->seconds();
        $this->withSession(['admin_authenticated' => true])->getJson('/api/admin/orders')->assertOk()->assertExactJson($data);
        Http::assertNothingSent();
    }

    public function test_missing_list_returns_503_without_upstream(): void
    {
        $this->withSession(['admin_authenticated' => true])->getJson('/api/admin/orders')->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_fresh_detail_is_served_without_upstream(): void
    {
        $detail = $this->detail();
        $this->cache->putDetail(42, $detail);
        $this->withSession(['admin_authenticated' => true])->getJson('/api/admin/orders/42')->assertOk()->assertExactJson(['order' => $detail]);
        Http::assertNothingSent();
    }

    public function test_stale_detail_is_served_without_upstream(): void
    {
        $detail = $this->detail();
        $this->cache->putDetail(42, $detail);
        $this->travel(61)->seconds();
        $this->withSession(['admin_authenticated' => true])->getJson('/api/admin/orders/42')->assertOk()->assertExactJson(['order' => $detail]);
        Http::assertNothingSent();
    }

    public function test_missing_detail_is_loaded_once_and_then_served_from_cache(): void
    {
        $detail = $this->detail();
        config(['services.apps_script.url' => 'https://script.example/exec', 'services.apps_script.api_key' => 'test-key']);
        Http::fake(function ($request) use ($detail) {
            $this->assertSame('admin_get_order', $request->data()['action']);

            return Http::response(['ok' => true, 'data' => ['order' => $detail]], 200);
        });

        $this->withSession(['admin_authenticated' => true])->getJson('/api/admin/orders/42')->assertOk()->assertExactJson(['order' => $detail]);
        $this->assertSame($detail, $this->cache->detail(42)['data']);
        Http::assertSentCount(1);

        Http::fake();
        $this->withSession(['admin_authenticated' => true])->getJson('/api/admin/orders/42')->assertOk()->assertExactJson(['order' => $detail]);
        Http::assertNothingSent();
    }

    public function test_detail_refresh_failure_does_not_break_the_cached_list(): void
    {
        $list = $this->listData();
        $this->cache->putList(1, 25, $list);
        config(['services.apps_script.url' => 'https://script.example/exec', 'services.apps_script.api_key' => 'test-key']);
        Http::fake(['https://script.example/exec' => Http::response('temporary upstream failure', 503)]);

        $this->withSession(['admin_authenticated' => true])->getJson('/api/admin/orders/42')->assertStatus(503);
        $this->withSession(['admin_authenticated' => true])->getJson('/api/admin/orders')->assertOk()->assertExactJson($list);
        Http::assertSentCount(3);
    }

    public function test_refresh_lock_returns_callback_result(): void
    {
        $this->assertSame(['ok' => true], $this->cache->withRefreshLock('test-list', static fn (): array => ['ok' => true]));
    }

    public function test_scheduler_refreshes_list_without_precaching_each_detail(): void
    {
        config()->set('services.apps_script.url', 'https://script.example/exec');
        config()->set('services.apps_script.api_key', 'test-key');
        Http::fake(function ($request) {
            $payload = $request->data();
            if ($payload['action'] === 'admin_list_orders') return Http::response(['ok' => true, 'data' => $this->listData()], 200);
            $this->fail('The list refresh must not request individual order details.');
        });

        $this->artisan('orders:refresh-admin-cache')->assertSuccessful();
        $this->assertNotNull($this->cache->list(1, 25));
        $this->assertNull($this->cache->detail(42));
        Http::assertSentCount(1);
    }

    public function test_scheduler_failure_preserves_existing_cache(): void
    {
        $data = $this->listData();
        $this->cache->putList(1, 25, $data);
        config()->set('services.apps_script.url', 'https://script.example/exec');
        config()->set('services.apps_script.api_key', 'test-key');
        Http::fake(['https://script.example/exec' => Http::response([], 503)]);
        $this->artisan('orders:refresh-admin-cache')->assertFailed();
        $this->assertSame($data, $this->cache->list(1, 25)['data']);
    }

    /** @dataProvider statusTransitions */
    public function test_operational_status_transitions_are_validated(string $from, string $payment, string $target, int $expectedStatus): void
    {
        config(['services.apps_script.url' => 'https://script.example/exec', 'services.apps_script.api_key' => 'test-key']);
        Http::fake(function ($request) use ($from, $payment, $target) {
            $data = $request->data();
            if ($data['action'] === 'admin_get_order') return Http::response(['ok' => true, 'data' => ['order' => $this->detail(42, $from, $payment)]], 200);
            return Http::response(['ok' => true, 'data' => ['order_id' => 42, 'status' => $target, 'updated_at' => '2026-09-21T13:05:20.000Z', 'revision' => 2, 'idempotency_replayed' => false]], 200);
        });

        $this->withSession(['admin_authenticated' => true])->patchJson('/api/admin/orders/42/status', ['status' => $target])->assertStatus($expectedStatus);
        Http::assertSentCount($expectedStatus === 200 ? 2 : 1);
    }

    public static function statusTransitions(): array
    {
        return [
            'paid pending to processing' => ['PENDING', 'APPROVED', 'PROCESSING', 200],
            'unpaid pending to processing' => ['PENDING', 'PENDING', 'PROCESSING', 422],
            'processing to ready' => ['PROCESSING', 'APPROVED', 'READY', 200],
            'ready to shipped' => ['READY', 'APPROVED', 'SHIPPED', 200],
            'ready direct delivered' => ['READY', 'APPROVED', 'DELIVERED', 200],
            'shipped to delivered' => ['SHIPPED', 'APPROVED', 'DELIVERED', 200],
            'pending to shipped rejected' => ['PENDING', 'APPROVED', 'SHIPPED', 422],
            'delivered final' => ['DELIVERED', 'APPROVED', 'PROCESSING', 422],
            'cancelled final' => ['CANCELLED', 'APPROVED', 'PROCESSING', 422],
        ];
    }

    private function listData(): array
    {
        return ['orders' => [['id' => 42, 'reference' => 'TTV-ADMIN-42', 'status' => 'PENDING', 'payment_status' => 'APPROVED', 'customer_name' => 'Cliente de Prueba', 'total' => 19500, 'created_at' => '2026-09-21T13:04:20.000Z']], 'pagination' => ['current_page' => 1, 'per_page' => 25, 'total' => 1, 'last_page' => 1]];
    }

    private function detail(int $id = 42, string $status = 'PENDING', string $paymentStatus = 'APPROVED'): array
    {
        return ['id' => $id, 'reference' => "TTV-ADMIN-{$id}", 'status' => $status, 'payment_status' => $paymentStatus, 'reservation_status' => 'CONSUMED', 'paid_at' => $paymentStatus === 'APPROVED' ? '2026-09-21T13:04:20.000Z' : null, 'payment' => null, 'customer_name' => 'Cliente de Prueba', 'customer_email' => 'cliente@example.test', 'customer_phone' => '3000000000', 'customer_document' => '1000000000', 'address' => 'Direccion de prueba', 'extra' => null, 'city' => 'Bogota', 'region' => 'Bogota D.C.', 'postal' => null, 'total' => 19500, 'created_at' => '2026-09-21T13:04:20.000Z', 'items' => [['id' => 9, 'product_id' => 193, 'product_name' => 'Producto historico', 'unit_price' => 19500, 'quantity' => 1]]];
    }
}
