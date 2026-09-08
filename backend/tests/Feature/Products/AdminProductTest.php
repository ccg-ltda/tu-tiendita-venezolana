<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AdminProductTest extends TestCase
{
    use DatabaseTransactions;

    public function test_an_unauthenticated_user_cannot_view_the_administrative_catalog(): void
    {
        $this->getJson('/api/admin/products')->assertUnauthorized();
    }

    public function test_a_customer_cannot_view_the_administrative_catalog(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $this->actingAs($customer)
            ->getJson('/api/admin/products')
            ->assertForbidden();
    }

    public function test_an_admin_can_view_active_and_inactive_products_using_the_administrative_contract(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $activeProduct = $this->createProduct(active: true);
        $inactiveProduct = $this->createProduct(active: false);

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/products')
            ->assertOk()
            ->assertJsonStructure(['products']);

        $payload = $response->json();
        $this->assertSame(['products'], array_keys($payload));

        $expectedFields = [
            'id', 'img', 'category', 'subcategory', 'name', 'presentation',
            'price', 'image', 'inventory', 'active',
        ];

        $ids = [];
        foreach ($payload['products'] as $product) {
            $this->assertSame($expectedFields, array_keys($product));
            $this->assertArrayNotHasKey('created_at', $product);
            $this->assertArrayNotHasKey('updated_at', $product);
            $ids[] = $product['id'];
        }

        $sortedIds = $ids;
        sort($sortedIds);

        $this->assertSame($sortedIds, $ids);
        $this->assertSame(true, collect($payload['products'])->firstWhere('id', $activeProduct->id)['active']);
        $this->assertSame(false, collect($payload['products'])->firstWhere('id', $inactiveProduct->id)['active']);
    }

    public function test_the_public_catalog_keeps_its_legacy_contract(): void
    {
        $product = $this->createProduct(active: true);

        $response = $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonStructure(['products']);

        $publicProduct = collect($response->json('products'))->firstWhere('id', $product->id);

        $this->assertSame([
            'id', 'img', 'cat', 'sub', 'name', 'pres', 'price', 'image', 'inventory', 'active',
        ], array_keys($publicProduct));
        $this->assertArrayNotHasKey('created_at', $publicProduct);
        $this->assertArrayNotHasKey('updated_at', $publicProduct);
    }

    private function createProduct(bool $active): Product
    {
        return Product::query()->create([
            'img' => 'test-image',
            'category' => 'Test category',
            'subcategory' => 'Test subcategory',
            'name' => $active ? 'Active test product' : 'Inactive test product',
            'presentation' => 'Unidad',
            'price' => 1000,
            'image' => 'assets/products/test.jpg',
            'inventory' => 20,
            'active' => $active,
        ]);
    }
}
