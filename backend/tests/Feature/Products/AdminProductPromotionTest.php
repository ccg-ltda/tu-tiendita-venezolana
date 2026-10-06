<?php

namespace Tests\Feature\Products;

use App\Contracts\GoogleSheetsValuesClient;
use App\Promotions\ProductPromotionPriceResolver;
use App\Services\CatalogSnapshotStore;
use App\Services\GoogleSheetsPromotionStore;
use App\Services\ProductPromotionAdminService;
use DateTimeImmutable;
use DateTimeZone;
use Tests\TestCase;

class AdminProductPromotionTest extends TestCase
{
    private string $directory;
    private MemoryPromotionSheets $sheets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'promotion-admin-'.bin2hex(random_bytes(8));
        $catalog = new CatalogSnapshotStore($this->directory);
        $catalog->writeAtomically([$this->product()]);
        $this->sheets = new MemoryPromotionSheets;
        $promotionStore = new GoogleSheetsPromotionStore($this->sheets);
        $service = new ProductPromotionAdminService($catalog, $promotionStore, new ProductPromotionPriceResolver, new \App\Services\CatalogPromotionSnapshotService($catalog, $promotionStore));
        $this->app->instance(ProductPromotionAdminService::class, $service);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->directory) ?: [] as $entry) if (!in_array($entry, ['.', '..'], true)) @unlink($this->directory.DIRECTORY_SEPARATOR.$entry);
        @rmdir($this->directory);
        parent::tearDown();
    }

    public function test_get_without_promotion_returns_an_equivalent_empty_state(): void
    {
        $this->admin()->getJson('/api/admin/products/7/promotion')->assertOk()->assertJsonPath('promotion', null)->assertJsonPath('status', 'NONE')->assertJsonPath('pricing.effective_price_cop', 20000);
    }

    public function test_get_with_promotion_returns_the_persisted_configuration(): void
    {
        $this->sheets->append($this->promotion());
        $this->admin()->getJson('/api/admin/products/7/promotion')->assertOk()->assertJsonPath('promotion.discount_value', 15)->assertJsonPath('status', 'ACTIVE');
    }

    public function test_admin_can_list_promotions_with_their_product_and_effective_pricing(): void
    {
        $this->sheets->append($this->promotion());

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
        $this->assertCount(1, $this->sheets->rows());
    }

    public function test_it_deactivates_without_deleting_the_row(): void
    {
        $this->sheets->append($this->promotion());
        $this->admin()->patchJson('/api/admin/products/7/promotion', $this->payload(['active' => false, 'expected_revision' => 1]))->assertOk()->assertJsonPath('promotion.active', false)->assertJsonPath('status', 'INACTIVE');
        $this->assertCount(1, $this->sheets->rows());
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
        $this->sheets->append($this->promotion());
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
            $this->sheets->replace([$this->promotion($changes)]);
            $this->admin()->getJson('/api/admin/products/7/promotion')->assertOk()->assertJsonPath('status', $status);
        }
    }

    private function admin()
    {
        return $this->withSession(['admin_authenticated' => true]);
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

final class MemoryPromotionSheets implements GoogleSheetsValuesClient
{
    private array $rows = [];
    private const HEADERS = ['product_id','active','discount_type','discount_value','starts_at','ends_at','updated_at','revision'];
    public function getValues(string $range): array { if (str_ends_with($range, '!1:1')) return [self::HEADERS]; return [self::HEADERS, ...$this->rows]; }
    public function batchGetValues(array $ranges): array { return array_map(fn (string $range) => $this->getValues($range), $ranges); }
    public function updateValues(string $range, array $values): array { preg_match('/!(?:A)(\d+):H\d+$/', $range, $matches); $this->rows[(int) $matches[1] - 2] = $values[0]; return []; }
    public function appendValues(string $range, array $values): array { foreach ($values as $value) $this->rows[] = $value; return []; }
    public function batchUpdateValues(array $data): array { return []; }
    public function append(array $promotion): void { $this->rows[] = array_values($promotion); }
    public function replace(array $promotions): void { $this->rows = array_map(static fn (array $promotion): array => array_values($promotion), $promotions); }
    public function rows(): array { return $this->rows; }
}
