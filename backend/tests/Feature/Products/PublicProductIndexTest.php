<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PublicProductIndexTest extends TestCase
{
    use DatabaseTransactions;

    public function test_the_public_catalog_preserves_the_legacy_product_contract(): void
    {
        $inactiveProduct = Product::query()->orderBy('id')->firstOrFail();
        $inactiveProduct->update(['active' => false]);

        $response = $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonStructure(['products']);

        $payload = $response->json();

        $this->assertSame(['products'], array_keys($payload));
        $this->assertNotEmpty($payload['products']);

        $expectedFields = [
            'id',
            'img',
            'cat',
            'sub',
            'name',
            'pres',
            'price',
            'image',
            'inventory',
            'active',
        ];

        $ids = [];
        foreach ($payload['products'] as $product) {
            $this->assertSame($expectedFields, array_keys($product));
            $this->assertIsInt($product['id']);
            $this->assertIsInt($product['price']);
            $this->assertIsInt($product['inventory']);
            $this->assertIsBool($product['active']);
            $this->assertArrayNotHasKey('created_at', $product);
            $this->assertArrayNotHasKey('updated_at', $product);
            $ids[] = $product['id'];
        }

        $sortedIds = $ids;
        sort($sortedIds);

        $this->assertSame($sortedIds, $ids);
        $this->assertNotContains($inactiveProduct->id, $ids);
    }
}
