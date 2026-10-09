<?php

namespace Tests\Feature\Products;

use App\Repositories\MySqlProductRepository;
use App\Repositories\MySqlProductPromotionRepository;
use Mockery;
use Tests\TestCase;

class PublicProductPromotionTest extends TestCase
{
    public function test_an_active_mysql_promotion_applies_to_a_mysql_product(): void
    {
        $this->catalog($this->promotion('percent', 15));
        $this->getJson('/api/products')->assertOk()->assertJsonPath('products.0.price', 17000)->assertJsonPath('products.0.promotion.originalPrice', 20000)->assertJsonPath('products.0.promotion.discountCop', 3000);
    }

    public function test_an_inactive_mysql_promotion_does_not_change_the_mysql_price(): void
    {
        $this->catalog($this->promotion('fixed', 5000, ['active' => false]));
        $this->getJson('/api/products')->assertOk()->assertJsonPath('products.0.price', 20000)->assertJsonMissingPath('products.0.promotion');
    }

    private function catalog(array $promotion): void
    {
        $repository = Mockery::mock(MySqlProductRepository::class);
        $repository->shouldReceive('all')->once()->andReturn([['product_id' => 7, 'category' => 'Despensa', 'subcategory' => 'Harinas', 'name' => 'Harina', 'presentation' => '1 kg', 'price_cop' => 20000, 'inventory' => 5, 'active' => true, 'image_path' => null, 'legacy_img' => null, 'revision' => 1]]);
        $this->app->instance(MySqlProductRepository::class, $repository);
        $promotions = Mockery::mock(MySqlProductPromotionRepository::class);
        $promotions->shouldReceive('byProductIds')->once()->andReturn([$promotion['product_id'] => $promotion]);
        $this->app->instance(MySqlProductPromotionRepository::class, $promotions);
    }

    private function promotion(string $type, int $value, array $changes = []): array
    {
        return array_replace(['product_id' => 7, 'active' => true, 'discount_type' => $type, 'discount_value' => $value, 'starts_at' => null, 'ends_at' => null, 'updated_at' => '2026-10-06T12:00:00.000Z', 'revision' => 1], $changes);
    }
}
