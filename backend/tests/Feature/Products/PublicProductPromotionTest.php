<?php

namespace Tests\Feature\Products;

use App\Services\CatalogSnapshotStore;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PublicProductPromotionTest extends TestCase
{
    private string $directory;
    private CatalogSnapshotStore $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'catalog-promotion-public-'.bin2hex(random_bytes(8));
        $this->catalog = new CatalogSnapshotStore($this->directory);
        $this->app->instance(CatalogSnapshotStore::class, $this->catalog);
        Cache::forget(CatalogSnapshotStore::CACHE_KEY);
    }

    protected function tearDown(): void
    {
        Cache::forget(CatalogSnapshotStore::CACHE_KEY);
        foreach (scandir($this->directory) ?: [] as $entry) if (!in_array($entry, ['.', '..'], true)) @unlink($this->directory.DIRECTORY_SEPARATOR.$entry);
        @rmdir($this->directory);
        parent::tearDown();
    }

    public function test_catalog_without_promotions_keeps_the_base_price_and_legacy_fields(): void
    {
        $this->catalog->writeAtomically([$this->product()]);
        $this->getJson('/api/products')->assertOk()->assertJsonPath('products.0.price', 20000)->assertJsonMissingPath('products.0.promotion');
    }

    public function test_an_active_percentage_promotion_changes_price_and_preserves_original_price(): void
    {
        $this->catalog->writeAtomically([$this->product($this->promotion('percent', 15))]);
        $this->getJson('/api/products')->assertOk()->assertJsonPath('products.0.price', 17000)->assertJsonPath('products.0.promotion.originalPrice', 20000)->assertJsonPath('products.0.promotion.discountCop', 3000)->assertJsonPath('products.0.promotion.type', 'percent');
    }

    public function test_future_expired_and_inactive_promotions_do_not_change_public_price(): void
    {
        $now = now('America/Bogota');
        foreach ([
            $this->promotion('percent', 15, ['starts_at' => $now->copy()->addDay()->format('Y-m-d\\TH:i:s.vP')]),
            $this->promotion('percent', 15, ['ends_at' => $now->copy()->subDay()->format('Y-m-d\\TH:i:s.vP')]),
            $this->promotion('percent', 15, ['active' => false]),
        ] as $promotion) {
            $this->catalog->writeAtomically([$this->product($promotion)]);
            Cache::forget(CatalogSnapshotStore::CACHE_KEY);
            $this->getJson('/api/products')->assertOk()->assertJsonPath('products.0.price', 20000)->assertJsonMissingPath('products.0.promotion');
        }
    }

    public function test_an_active_fixed_promotion_changes_public_price(): void
    {
        $this->catalog->writeAtomically([$this->product($this->promotion('fixed', 5000))]);
        $this->getJson('/api/products')->assertOk()->assertJsonPath('products.0.price', 15000)->assertJsonPath('products.0.promotion.discountCop', 5000)->assertJsonPath('products.0.promotion.type', 'fixed');
    }

    /** @param array<string,mixed>|null $promotion */
    private function product(?array $promotion = null): array
    {
        $product = ['product_id' => 7, 'category' => 'Despensa', 'subcategory' => 'Harinas', 'name' => 'Harina', 'presentation' => '1 kg', 'price_cop' => 20000, 'inventory' => 5, 'active' => true, 'image_path' => null, 'legacy_img' => null, 'revision' => 1];
        if ($promotion !== null) $product['promotion'] = $promotion;
        return $product;
    }

    /** @param array<string,mixed> $changes */
    private function promotion(string $type, int $value, array $changes = []): array
    {
        return array_replace(['product_id' => 7, 'active' => true, 'discount_type' => $type, 'discount_value' => $value, 'starts_at' => null, 'ends_at' => null, 'updated_at' => '2026-10-06T12:00:00.000Z', 'revision' => 1], $changes);
    }
}
