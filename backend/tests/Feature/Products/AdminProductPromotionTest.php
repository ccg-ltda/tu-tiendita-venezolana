<?php

namespace Tests\Feature\Products;

use App\Promotions\ProductPromotionPriceResolver;
use App\Repositories\MySqlProductPromotionRepository;
use App\Services\ProductPromotionAdminService;
use App\Repositories\MySqlProductRepository;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\AuthenticatesAdmin;
use Tests\TestCase;

class AdminProductPromotionTest extends TestCase
{
    use AuthenticatesAdmin;
    private MemoryPromotionRepository $promotionStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminDatabase();
        $this->promotionStore = new MemoryPromotionRepository;
        $products = \Mockery::mock(MySqlProductRepository::class);
        $products->shouldReceive('all')->andReturn([$this->product()]);
        $products->shouldReceive('findById')->andReturnUsing(fn (int $id): ?array => $id === 7 ? ['product' => $this->product()] : null);
        $service = new ProductPromotionAdminService($products, $this->promotionStore, new ProductPromotionPriceResolver);
        $this->app->instance(ProductPromotionAdminService::class, $service);
    }

    public function test_get_without_promotion_returns_an_equivalent_empty_state(): void
    {
        $this->admin()->getJson('/api/admin/products/7/promotion')->assertOk()->assertJsonPath('promotion', null)->assertJsonPath('status', 'NONE')->assertJsonPath('pricing.effective_price_cop', 20000);
    }

    public function test_get_with_promotion_returns_the_persisted_configuration(): void
    {
        $this->promotionStore->append($this->promotion());
        $this->admin()->getJson('/api/admin/products/7/promotion')->assertOk()->assertJsonPath('promotion.discount_value', 15)->assertJsonPath('status', 'ACTIVE');
    }

    public function test_admin_can_list_promotions_with_their_product_and_effective_pricing(): void
    {
        $this->promotionStore->append($this->promotion());

        $this->admin()->getJson('/api/admin/promotions')->assertOk()
            ->assertJsonPath('promotions.0.product.id', 7)
            ->assertJsonPath('promotions.0.product.name', 'Harina')
            ->assertJsonPath('promotions.0.pricing.base_price_cop', 20000)
            ->assertJsonPath('promotions.0.pricing.effective_price_cop', 17000)
            ->assertJsonPath('promotions.0.status', 'ACTIVE');
    }

    public function test_it_creates_and_edits_a_promotion(): void
    {
        $created = $this->admin()->patchJson('/api/admin/products/7/promotion', $this->payload())->assertOk()->assertJsonPath('promotion.revision', 1)->assertJsonPath('pricing.effective_price_cop', 17000);
        $this->admin()->patchJson('/api/admin/products/7/promotion', $this->payload(['discount_value' => 20, 'expected_revision' => $created->json('promotion.revision')]))->assertOk()->assertJsonPath('promotion.revision', 2)->assertJsonPath('pricing.effective_price_cop', 16000);
        $this->assertCount(1, $this->promotionStore->rows());
    }

    public function test_it_deactivates_without_deleting_the_row(): void
    {
        $this->promotionStore->append($this->promotion());
        $this->admin()->patchJson('/api/admin/products/7/promotion', $this->payload(['active' => false, 'expected_revision' => 1]))->assertOk()->assertJsonPath('promotion.active', false)->assertJsonPath('status', 'INACTIVE');
        $this->assertCount(1, $this->promotionStore->rows());
    }

    public function test_it_rejects_invalid_percentage_and_fixed_amounts(): void
    {
        $this->admin()->patchJson('/api/admin/products/7/promotion', $this->payload(['discount_type' => 'percent', 'discount_value' => 100]))->assertStatus(422);
        $this->admin()->patchJson('/api/admin/products/7/promotion', $this->payload(['discount_type' => 'fixed', 'discount_value' => 20000]))->assertStatus(422);
    }

    public function test_it_rejects_an_unknown_product_and_an_invalid_date_range(): void
    {
        $this->admin()->patchJson('/api/admin/products/999/promotion', $this->payload())->assertNotFound();
        $this->admin()->patchJson('/api/admin/products/7/promotion', $this->payload(['starts_at' => '2026-10-08T00:00:00.000-05:00', 'ends_at' => '2026-10-07T00:00:00.000-05:00']))->assertStatus(422);
    }

    public function test_it_rejects_a_stale_promotion_revision(): void
    {
        $this->promotionStore->append($this->promotion());
        $this->admin()->patchJson('/api/admin/products/7/promotion', $this->payload(['expected_revision' => 2]))->assertConflict();
    }

    public function test_it_derives_active_scheduled_expired_and_inactive_states(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('America/Bogota'));
        foreach ([
            ['ACTIVE', []],
            ['SCHEDULED', ['starts_at' => $now->modify('+1 day')->format('Y-m-d\\TH:i:s.vP')]],
            ['EXPIRED', ['ends_at' => $now->modify('-1 day')->format('Y-m-d\\TH:i:s.vP')]],
            ['INACTIVE', ['active' => false]],
        ] as [$status, $changes]) {
            $this->promotionStore->replace([$this->promotion($changes)]);
            $this->admin()->getJson('/api/admin/products/7/promotion')->assertOk()->assertJsonPath('status', $status);
        }
    }

    private function admin()
    {
        return $this->authenticatedAdmin();
    }

    /** @param array<string,mixed> $changes */
    private function payload(array $changes = []): array
    {
        return array_replace(['active' => true, 'discount_type' => 'percent', 'discount_value' => 15, 'starts_at' => null, 'ends_at' => null, 'expected_revision' => null], $changes);
    }

    /** @param array<string,mixed> $changes */
    private function promotion(array $changes = []): array
    {
        return array_replace(['product_id' => 7, 'active' => true, 'discount_type' => 'percent', 'discount_value' => 15, 'starts_at' => null, 'ends_at' => null, 'updated_at' => '2026-10-06T12:00:00.000Z', 'revision' => 1], $changes);
    }

    private function product(): array
    {
        return ['product_id' => 7, 'category' => 'Despensa', 'subcategory' => 'Harinas', 'name' => 'Harina', 'presentation' => '1 kg', 'price_cop' => 20000, 'inventory' => 5, 'active' => true, 'image_path' => null, 'legacy_img' => null, 'revision' => 1];
    }
}

final class MemoryPromotionRepository extends MySqlProductPromotionRepository
{
    private array $rows = [];
    public function all(): array { return array_values($this->rows); }
    public function findByProductId(int $productId): ?array { return $this->rows[$productId] ?? null; }
    public function save(array $candidate, ?int $expectedRevision): array { $existing=$this->findByProductId($candidate['product_id']); if(($existing===null&&$expectedRevision!==null)||($existing!==null&&$existing['revision']!==$expectedRevision)) throw new \App\Services\PersistenceException(409,'PROMOTION_REVISION_CONFLICT'); $candidate['revision']=$existing===null?1:$existing['revision']+1; $this->rows[$candidate['product_id']]=$candidate; return $candidate; }
    public function append(array $promotion): void { $this->rows[$promotion['product_id']] = $promotion; }
    public function replace(array $promotions): void { $this->rows=[]; foreach($promotions as $promotion)$this->append($promotion); }
    public function rows(): array { return $this->rows; }
}
