<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\NotificationOutbox;
use App\Models\Order;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('mysql')]
final class AdminRolesTest extends TestCase
{
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        $database = config('database.connections.mysql.database');
        if (! is_string($database) || ! str_ends_with($database, '_test')) {
            throw new \RuntimeException('Estas pruebas exigen una base de datos que termine en _test.');
        }
        Artisan::call('migrate', ['--database' => 'mysql', '--force' => true]);
        $this->tag = 'roles-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $ids = Order::query()->where('customer_name', 'like', $this->tag.'%')->pluck('id');
        if ($ids->isNotEmpty()) {
            NotificationOutbox::query()->whereIn('order_id', $ids)->delete();
            Order::query()->whereIn('id', $ids)->delete();
        }
        Admin::query()->where('username', 'like', $this->tag.'%')->delete();
        parent::tearDown();
    }

    private function asRole(string $role): static
    {
        $admin = Admin::query()->firstOrCreate(['username' => $this->tag.'-'.strtolower($role)], [
            'name' => $this->tag, 'password_hash' => Hash::make('CorrectPassword12!'),
            'role' => $role, 'active' => true, 'password_changed_at' => now('UTC'), 'revision' => 1,
        ]);

        return $this->withSession([
            'admin_authenticated' => true, 'admin_id' => $admin->id, 'admin_name' => $admin->name,
            'admin_username' => $admin->username, 'admin_role' => $admin->role, 'admin_revision' => $admin->revision,
        ]);
    }

    private function order(string $status): Order
    {
        return Order::query()->create([
            'reference' => $this->tag.'-'.bin2hex(random_bytes(4)), 'status' => $status, 'payment_status' => 'APPROVED',
            'reservation_status' => 'CONSUMED', 'idempotency_key_hash' => bin2hex(random_bytes(32)), 'checkout_payload_hash' => bin2hex(random_bytes(32)),
            'customer_name' => $this->tag.' Cliente', 'customer_email' => 'secreto@example.com', 'customer_phone' => '3001112233',
            'customer_document' => '1234567890', 'address' => 'Calle 1 # 2-3', 'city' => 'Bogotá', 'region' => 'Bogotá D.C.',
            'total_cop' => 50000, 'shipping_cop' => 0, 'revision' => 1,
        ]);
    }

    public function test_despacho_and_entrega_cannot_reach_admin_only_areas(): void
    {
        foreach (['DESPACHO', 'ENTREGA'] as $role) {
            foreach (['/api/admin/products', '/api/admin/coupons', '/api/admin/audit', '/api/admin/dashboard', '/api/admin/customers'] as $url) {
                $this->asRole($role)->getJson($url)->assertForbidden();
            }
        }
        $this->asRole('ADMIN')->getJson('/api/admin/products')->assertOk();
    }

    public function test_despacho_sees_contact_and_items_but_not_email_or_document(): void
    {
        $order = $this->order('PROCESSING');
        $this->asRole('DESPACHO')->getJson("/api/admin/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order.customer_phone', '3001112233')
            ->assertJsonPath('order.address', 'Calle 1 # 2-3')
            ->assertJsonMissingPath('order.customer_email')
            ->assertJsonMissingPath('order.customer_document');
        $this->asRole('ADMIN')->getJson("/api/admin/orders/{$order->id}")
            ->assertOk()->assertJsonPath('order.customer_email', 'secreto@example.com');
    }

    public function test_despacho_moves_an_order_forward_but_cannot_set_it_back_to_pending(): void
    {
        $order = $this->order('PROCESSING');
        $this->asRole('DESPACHO')->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'READY'])->assertOk();
        $this->asRole('DESPACHO')->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'PENDING'])->assertForbidden();
    }

    public function test_entrega_only_sees_shipped_orders_and_only_marks_them_delivered(): void
    {
        $shipped = $this->order('SHIPPED');
        $processing = $this->order('PROCESSING');

        $this->asRole('ENTREGA')->getJson("/api/admin/orders/{$processing->id}")->assertNotFound();
        $this->asRole('ENTREGA')->patchJson("/api/admin/orders/{$processing->id}/status", ['status' => 'DELIVERED'])->assertNotFound();
        $this->asRole('ENTREGA')->patchJson("/api/admin/orders/{$shipped->id}/status", ['status' => 'READY'])->assertForbidden();

        $ids = $this->asRole('ENTREGA')->getJson('/api/admin/orders?per_page=100')->assertOk()->json('orders.*.id');
        $this->assertContains($shipped->id, $ids);
        $this->assertNotContains($processing->id, $ids);

        $this->asRole('ENTREGA')->patchJson("/api/admin/orders/{$shipped->id}/status", ['status' => 'DELIVERED'])
            ->assertOk()->assertJsonMissingPath('order.customer_email');
        $this->assertSame('DELIVERED', $shipped->fresh()->status);
    }
}
