<?php

namespace Tests\Feature\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CreateOrderTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_valid_order_uses_database_prices_and_decreases_inventory(): void
    {
        $product = $this->product(price: 11250, inventory: 5);

        $response = $this->postJson('/api/orders', $this->payload([
            ['id' => $product->id, 'qty' => 2, 'price' => 1],
        ]));

        $response->assertCreated();
        $this->assertSame(['order'], array_keys($response->json()));
        $this->assertSame(['id', 'reference', 'status', 'total'], array_keys($response->json('order')));
        $response->assertJsonPath('order.status', 'PENDING')
            ->assertJsonPath('order.total', 22500);
        $this->assertMatchesRegularExpression('/^TTV-[A-Z0-9]+-[A-F0-9]{4}$/', $response->json('order.reference'));

        $order = Order::query()->findOrFail($response->json('order.id'));
        $item = OrderItem::query()->where('order_id', $order->id)->firstOrFail();

        $this->assertSame(1, OrderItem::query()->where('order_id', $order->id)->count());
        $this->assertSame($product->name, $item->product_name);
        $this->assertSame(11250, $item->unit_price);
        $this->assertSame(2, $item->quantity);
        $this->assertSame(3, $product->fresh()->inventory);
    }

    public function test_client_supplied_price_does_not_change_the_total_or_snapshot(): void
    {
        $product = $this->product(price: 7000, inventory: 4);

        $response = $this->postJson('/api/orders', $this->payload([
            ['id' => $product->id, 'qty' => 2, 'price' => 1],
        ]))->assertCreated();

        $this->assertSame(14000, $response->json('order.total'));
        $this->assertSame(7000, OrderItem::query()->where('order_id', $response->json('order.id'))->value('unit_price'));
    }

    public function test_a_missing_product_returns_a_conflict_without_writing_an_order(): void
    {
        $orderCountBefore = DB::table('orders')->count();
        $itemCountBefore = DB::table('order_items')->count();

        $this->postJson('/api/orders', $this->payload([['id' => 999999, 'qty' => 1]]))
            ->assertConflict()
            ->assertExactJson(['error' => 'Uno de los productos ya no está disponible.']);

        $this->assertSame($orderCountBefore, DB::table('orders')->count());
        $this->assertSame($itemCountBefore, DB::table('order_items')->count());
    }

    public function test_an_inactive_product_returns_a_conflict_without_writing_an_order(): void
    {
        $product = $this->product(active: false);
        $orderCountBefore = DB::table('orders')->count();
        $itemCountBefore = DB::table('order_items')->count();

        $this->postJson('/api/orders', $this->payload([['id' => $product->id, 'qty' => 1]]))
            ->assertConflict()
            ->assertExactJson(['error' => 'Uno de los productos ya no está disponible.']);

        $this->assertSame($orderCountBefore, DB::table('orders')->count());
        $this->assertSame($itemCountBefore, DB::table('order_items')->count());
        $this->assertSame(5, $product->fresh()->inventory);
    }

    public function test_insufficient_stock_returns_a_conflict_without_changing_inventory(): void
    {
        $product = $this->product(inventory: 1);
        $orderCountBefore = DB::table('orders')->count();
        $itemCountBefore = DB::table('order_items')->count();

        $this->postJson('/api/orders', $this->payload([['id' => $product->id, 'qty' => 2]]))
            ->assertConflict()
            ->assertExactJson(['error' => "Solo quedan 1 unidades de {$product->name}."]);

        $this->assertSame($orderCountBefore, DB::table('orders')->count());
        $this->assertSame($itemCountBefore, DB::table('order_items')->count());
        $this->assertSame(1, $product->fresh()->inventory);
    }

    public function test_an_incomplete_customer_returns_the_legacy_validation_error(): void
    {
        $payload = $this->payload([['id' => 1, 'qty' => 1]]);
        unset($payload['customer']['phone']);

        $this->postJson('/api/orders', $payload)
            ->assertStatus(400)
            ->assertExactJson(['error' => 'Completa todos los datos obligatorios.']);
    }

    public function test_an_empty_items_array_returns_the_legacy_validation_error(): void
    {
        $this->postJson('/api/orders', $this->payload([]))
            ->assertStatus(400)
            ->assertExactJson(['error' => 'El pedido no tiene productos.']);
    }

    public function test_an_invalid_quantity_is_rejected_without_writing(): void
    {
        $product = $this->product();
        $orderCountBefore = DB::table('orders')->count();
        $itemCountBefore = DB::table('order_items')->count();

        $this->postJson('/api/orders', $this->payload([['id' => $product->id, 'qty' => 0]]))
            ->assertStatus(400)
            ->assertExactJson(['error' => 'El pedido contiene productos inválidos.']);

        $this->assertSame($orderCountBefore, DB::table('orders')->count());
        $this->assertSame($itemCountBefore, DB::table('order_items')->count());
        $this->assertSame(5, $product->fresh()->inventory);
    }

    public function test_repeated_product_ids_are_consolidated_before_inventory_is_decreased(): void
    {
        $product = $this->product(price: 9000, inventory: 7);

        $response = $this->postJson('/api/orders', $this->payload([
            ['id' => $product->id, 'qty' => 2],
            ['id' => $product->id, 'qty' => 3],
        ]))->assertCreated();

        $this->assertSame(45000, $response->json('order.total'));
        $this->assertSame(1, OrderItem::query()
            ->where('order_id', $response->json('order.id'))
            ->where('product_id', $product->id)
            ->count());
        $this->assertSame(5, OrderItem::query()->where('order_id', $response->json('order.id'))->value('quantity'));
        $this->assertSame(2, $product->fresh()->inventory);
    }

    public function test_a_failure_in_one_of_multiple_products_rolls_back_every_change(): void
    {
        $product = $this->product(price: 5000, inventory: 5);
        $orderCountBefore = DB::table('orders')->count();
        $itemCountBefore = DB::table('order_items')->count();
        $inventoryBefore = $product->inventory;

        $this->postJson('/api/orders', $this->payload([
            ['id' => $product->id, 'qty' => 2],
            ['id' => 999999, 'qty' => 1],
        ]))->assertConflict();

        $this->assertSame($orderCountBefore, DB::table('orders')->count());
        $this->assertSame($itemCountBefore, DB::table('order_items')->count());
        $this->assertSame($inventoryBefore, $product->fresh()->inventory);
    }

    /** @param list<array<string, int>> $items
     *  @return array<string, mixed>
     */
    private function payload(array $items): array
    {
        return [
            'customer' => [
                'name' => 'Cliente de Prueba',
                'email' => 'cliente@example.test',
                'phone' => '3000000000',
                'document' => '1000000000',
                'address' => 'Calle de Prueba 1',
                'extra' => 'Apartamento 2',
                'city' => 'Bogotá',
                'region' => 'Bogotá D.C.',
                'postal' => '110111',
            ],
            'items' => $items,
        ];
    }

    private function product(int $price = 1000, int $inventory = 5, bool $active = true): Product
    {
        return Product::query()->create([
            'img' => 'test-image',
            'category' => 'Test category',
            'subcategory' => 'Test subcategory',
            'name' => 'Test product',
            'presentation' => 'Unidad',
            'price' => $price,
            'image' => 'assets/products/test.jpg',
            'inventory' => $inventory,
            'active' => $active,
        ]);
    }
}
