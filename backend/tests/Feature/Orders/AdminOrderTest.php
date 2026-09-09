<?php

namespace Tests\Feature\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AdminOrderTest extends TestCase
{
    use DatabaseTransactions;

    public function test_an_unauthenticated_user_cannot_list_or_view_orders(): void
    {
        $order = $this->order();

        $this->getJson('/api/admin/orders')->assertUnauthorized();
        $this->getJson("/api/admin/orders/{$order->id}")->assertUnauthorized();
    }

    public function test_a_customer_cannot_list_or_view_orders(): void
    {
        $order = $this->order();
        $customer = User::factory()->create(['role' => 'customer']);

        $this->actingAs($customer)->getJson('/api/admin/orders')->assertForbidden();
        $this->actingAs($customer)->getJson("/api/admin/orders/{$order->id}")->assertForbidden();
    }

    public function test_an_admin_can_list_orders_in_descending_id_order_with_an_explicit_paginated_contract(): void
    {
        $totalBefore = Order::query()->count();
        $first = $this->order(['customer_name' => 'First customer']);
        $second = $this->order(['customer_name' => 'Second customer']);

        $response = $this->actingAs($this->admin())->getJson('/api/admin/orders?per_page=1')->assertOk();

        $this->assertSame(['orders', 'pagination'], array_keys($response->json()));
        $this->assertSame(1, $response->json('pagination.current_page'));
        $this->assertSame(1, $response->json('pagination.per_page'));
        $this->assertSame($totalBefore + 2, $response->json('pagination.total'));
        $this->assertSame((int) ceil(($totalBefore + 2) / 1), $response->json('pagination.last_page'));
        $this->assertSame([$second->id], collect($response->json('orders'))->pluck('id')->all());

        $listedOrder = $response->json('orders.0');
        $this->assertSame($this->listFields(), array_keys($listedOrder));
        $this->assertSame($second->reference, $listedOrder['reference']);
        $this->assertSame('PENDING', $listedOrder['status']);
        foreach (['customer_email', 'customer_phone', 'customer_document', 'address', 'extra', 'city', 'region', 'postal', 'items'] as $field) {
            $this->assertArrayNotHasKey($field, $listedOrder);
        }
        $this->assertGreaterThan($first->id, $second->id);
    }

    public function test_the_order_list_caps_per_page_at_one_hundred(): void
    {
        $response = $this->actingAs($this->admin())->getJson('/api/admin/orders?per_page=1000')->assertOk();

        $response->assertJsonPath('pagination.per_page', 100);
    }

    public function test_an_admin_can_view_an_order_detail_with_snapshot_items_without_changing_inventory(): void
    {
        $product = $this->product(inventory: 13);
        $order = $this->order([
            'customer_name' => 'Customer name',
            'customer_email' => 'customer@example.test',
            'customer_phone' => '3000000000',
            'customer_document' => '1000000000',
            'address' => 'Test address',
            'extra' => 'Apartment 2',
            'city' => 'Bogota',
            'region' => 'Bogota D.C.',
            'postal' => '110111',
            'total' => 22500,
        ]);
        $firstItem = OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Historical name',
            'unit_price' => 11250,
            'quantity' => 2,
        ]);

        $response = $this->actingAs($this->admin())->getJson("/api/admin/orders/{$order->id}")->assertOk();
        $detail = $response->json('order');

        $this->assertSame(['order'], array_keys($response->json()));
        $this->assertSame($this->detailFields(), array_keys($detail));
        $this->assertSame($order->reference, $detail['reference']);
        $this->assertSame('Customer name', $detail['customer_name']);
        $this->assertSame('customer@example.test', $detail['customer_email']);
        $this->assertSame(22500, $detail['total']);
        $this->assertCount(1, $detail['items']);
        $this->assertSame($this->itemFields(), array_keys($detail['items'][0]));
        $this->assertSame($firstItem->id, $detail['items'][0]['id']);
        $this->assertSame($product->id, $detail['items'][0]['product_id']);
        $this->assertSame('Historical name', $detail['items'][0]['product_name']);
        $this->assertSame(11250, $detail['items'][0]['unit_price']);
        $this->assertSame(2, $detail['items'][0]['quantity']);
        $this->assertSame(13, $product->fresh()->inventory);
    }

    public function test_an_admin_receives_not_found_for_a_missing_order(): void
    {
        $this->actingAs($this->admin())->getJson('/api/admin/orders/999999999')->assertNotFound();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** @param array<string, mixed> $overrides */
    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'reference' => 'TTV-TEST-'.strtoupper(uniqid()),
            'status' => 'PENDING',
            'customer_name' => 'Test customer',
            'customer_email' => 'test@example.test',
            'customer_phone' => '3000000000',
            'customer_document' => '1000000000',
            'address' => 'Test address',
            'extra' => null,
            'city' => 'Bogota',
            'region' => 'Bogota D.C.',
            'postal' => null,
            'total' => 1000,
            'created_at' => now(),
        ], $overrides));
    }

    private function product(int $inventory): Product
    {
        return Product::query()->create([
            'img' => 'test-image',
            'category' => 'Test category',
            'subcategory' => 'Test subcategory',
            'name' => 'Current product name',
            'presentation' => 'Unit',
            'price' => 1000,
            'image' => 'assets/products/test.jpg',
            'inventory' => $inventory,
            'active' => true,
        ]);
    }

    /** @return list<string> */
    private function listFields(): array
    {
        return ['id', 'reference', 'status', 'customer_name', 'total', 'created_at'];
    }

    /** @return list<string> */
    private function detailFields(): array
    {
        return [
            'id', 'reference', 'status', 'customer_name', 'customer_email', 'customer_phone',
            'customer_document', 'address', 'extra', 'city', 'region', 'postal', 'total',
            'created_at', 'items',
        ];
    }

    /** @return list<string> */
    private function itemFields(): array
    {
        return ['id', 'product_id', 'product_name', 'unit_price', 'quantity'];
    }
}
