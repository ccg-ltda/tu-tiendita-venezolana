<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\NotificationOutbox;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('mysql')]
final class AdminManualOrderTest extends TestCase
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
        $this->tag = 'manual-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $orderIds = Order::query()->where('customer_name', 'like', $this->tag.'%')->pluck('id');
        if ($orderIds->isNotEmpty()) {
            OrderAttachment::query()->whereIn('order_id', $orderIds)->delete();
            NotificationOutbox::query()->whereIn('order_id', $orderIds)->delete();
            OrderItem::query()->whereIn('order_id', $orderIds)->delete();
            Order::query()->whereIn('id', $orderIds)->delete();
        }
        Product::query()->where('name', 'like', $this->tag.'%')->delete();
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

    private function product(int $inventory = 10, int $price = 20000): Product
    {
        return Product::query()->create([
            'category' => 'Pruebas', 'subcategory' => 'Pruebas', 'name' => $this->tag.' Harina', 'presentation' => '1 kg',
            'price_cop' => $price, 'inventory' => $inventory, 'active' => true, 'revision' => 1,
        ]);
    }

    private function payload(Product $product, array $override = []): array
    {
        return array_replace_recursive([
            'customer' => ['name' => $this->tag.' Rosa Pardo', 'phone' => '300 123 4567', 'city' => 'Bogotá', 'address' => 'Carrera 7 # 45-10'],
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payment_method' => 'TRANSFERENCIA', 'paid' => true,
        ], $override);
    }

    public function test_only_admin_can_create_a_manual_order(): void
    {
        $product = $this->product();
        $this->asRole('DESPACHO')->postJson('/api/admin/orders/manual', $this->payload($product))->assertForbidden();
        $this->asRole('ENTREGA')->postJson('/api/admin/orders/manual', $this->payload($product))->assertForbidden();
        $this->assertSame(10, $product->fresh()->inventory);
    }

    public function test_admin_creates_a_paid_manual_order_and_inventory_is_discounted(): void
    {
        $product = $this->product();
        $response = $this->asRole('ADMIN')->postJson('/api/admin/orders/manual', $this->payload($product, ['shipping_cop' => 0, 'note' => 'Pidió por WhatsApp']))
            ->assertCreated()->assertJsonPath('order.total_cop', 40000)->assertJsonPath('order.payment_status', 'APPROVED');

        $order = Order::query()->findOrFail($response->json('order.order_id'));
        $this->assertSame('MANUAL', $order->channel);
        $this->assertSame('PENDING', $order->status);
        $this->assertSame('3001234567', $order->customer_phone);
        $this->assertStringEndsWith('@sin-correo.tutienditavenezolana.com', $order->customer_email);
        $this->assertSame(8, $product->fresh()->inventory);

        $this->asRole('DESPACHO')->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'PROCESSING'])->assertOk();
        $this->assertSame(0, NotificationOutbox::query()->where('order_id', $order->id)->count(), 'No se envía correo a un cliente sin correo.');
    }

    public function test_pay_on_delivery_order_is_operational_even_when_unpaid(): void
    {
        $product = $this->product();
        $id = $this->asRole('ADMIN')->postJson('/api/admin/orders/manual', $this->payload($product, ['payment_method' => 'CONTRAENTREGA', 'paid' => true]))
            ->assertCreated()->assertJsonPath('order.payment_status', 'PENDING')->json('order.order_id');

        $ids = $this->asRole('ADMIN')->getJson('/api/admin/orders?flow_status=operational&per_page=100')->assertOk()->json('orders.*.id');
        $this->assertContains($id, $ids);
        $this->asRole('DESPACHO')->patchJson("/api/admin/orders/{$id}/status", ['status' => 'PROCESSING'])->assertOk();
    }

    public function test_it_refuses_when_there_is_not_enough_inventory(): void
    {
        $product = $this->product(1);
        $this->asRole('ADMIN')->postJson('/api/admin/orders/manual', $this->payload($product))
            ->assertStatus(422)->assertJsonPath('message', 'No hay inventario suficiente de uno de los productos.');
        $this->assertSame(1, $product->fresh()->inventory);
    }

    public function test_payment_proof_is_stored_and_only_admin_can_open_it(): void
    {
        $product = $this->product();
        $png = base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        $id = $this->asRole('ADMIN')->postJson('/api/admin/orders/manual', $this->payload($product, ['attachment' => ['name' => 'soporte.png', 'mime' => 'image/png', 'data' => $png]]))
            ->assertCreated()->json('order.order_id');
        $attachment = OrderAttachment::query()->where('order_id', $id)->firstOrFail();

        $this->asRole('ADMIN')->get("/api/admin/orders/{$id}/attachments/{$attachment->id}")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->asRole('DESPACHO')->get("/api/admin/orders/{$id}/attachments/{$attachment->id}")->assertForbidden();
    }
}
