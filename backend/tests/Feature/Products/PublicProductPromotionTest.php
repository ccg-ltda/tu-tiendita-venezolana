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

    public function test_it_returns_an_absolute_api_url_for_a_new_product_image(): void
    {
        config()->set('filesystems.disks.public.url', 'https://api.tutienditavenezolana.com/storage');
        $this->catalog($this->promotion('percent', 15), '/storage/products/'.str_repeat('a', 32).'.png');

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('products.0.image', 'https://api.tutienditavenezolana.com/storage/products/'.str_repeat('a', 32).'.png');
    }

    public function test_it_keeps_a_legacy_product_image_path_relative_to_the_frontend(): void
    {
        $this->catalog($this->promotion('percent', 15), '/assets/products/01.jpg');

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('products.0.image', '/assets/products/01.jpg');
    }

    private function catalog(array $promotion, ?string $imagePath = null): void
    {
        $repository = Mockery::mock(MySqlProductRepository::class);
        $repository->shouldReceive('all')->once()->andReturn([['product_id' => 7, 'category' => 'Despensa', 'subcategory' => 'Harinas', 'name' => 'Harina', 'presentation' => '1 kg', 'price_cop' => 20000, 'inventory' => 5, 'active' => true, 'image_path' => $imagePath, 'legacy_img' => null, 'revision' => 1]]);
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
