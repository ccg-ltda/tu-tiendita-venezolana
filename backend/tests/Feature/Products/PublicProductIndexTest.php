<?php

namespace Tests\Feature\Products;

use App\Services\CatalogSnapshotStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicProductIndexTest extends TestCase
{
    private string $directory;
    private CatalogSnapshotStore $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'catalog-public-'.bin2hex(random_bytes(8));
        $this->catalog = new CatalogSnapshotStore($this->directory);
        $this->app->instance(CatalogSnapshotStore::class, $this->catalog);
        Cache::forget(CatalogSnapshotStore::CACHE_KEY);
    }

    protected function tearDown(): void
    {
        Cache::forget(CatalogSnapshotStore::CACHE_KEY);
        $this->removeDirectory($this->directory);

        parent::tearDown();
    }

    public function test_the_public_catalog_preserves_the_legacy_contract_from_the_local_snapshot(): void
    {
        $this->catalog->writeAtomically([
            $this->product(20, '20', true),
            $this->product(2, '01', true),
            $this->product(4, '04', false),
        ]);
        Cache::forget(CatalogSnapshotStore::CACHE_KEY);
        Http::fake();

        $response = $this->getJson('/api/products')->assertOk();

        $this->assertSame(['products' => [
            $this->publicProduct(2, '01', true),
            $this->publicProduct(20, '20', true),
        ]], $response->json());
        Http::assertNothingSent();
    }

    public function test_a_cache_hit_does_not_read_upstream_or_require_the_snapshot_file(): void
    {
        $this->catalog->replaceCache([$this->product(2, '01', true)]);
        Http::fake();

        $this->getJson('/api/products')->assertOk();

        Http::assertNothingSent();
    }

    public function test_a_missing_snapshot_returns_a_fast_generic_503_without_calling_apps_script(): void
    {
        Http::fake();

        $this->getJson('/api/products')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '60')
            ->assertJsonStructure(['message']);

        Http::assertNothingSent();
    }

    public function test_a_corrupt_snapshot_returns_a_fast_generic_503_without_calling_apps_script(): void
    {
        mkdir($this->directory, 0750, true);
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.'products.json', '{invalid');
        Http::fake();

        $this->getJson('/api/products')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '60');

        Http::assertNothingSent();
    }

    /** @return array<string, int|bool|string> */
    private function product(int $id, string $legacyImg, bool $active): array
    {
        return [
            'product_id' => $id,
            'category' => 'Despensa',
            'subcategory' => 'Harinas',
            'name' => "Producto {$id}",
            'presentation' => 'Unidad',
            'price_cop' => 12500,
            'inventory' => 7,
            'active' => $active,
            'image_path' => "assets/products/{$legacyImg}.jpg",
            'legacy_img' => $legacyImg,
            'revision' => 1,
        ];
    }

    /** @return array<string, int|bool|string> */
    private function publicProduct(int $id, string $legacyImg, bool $active): array
    {
        return [
            'id' => $id,
            'img' => $legacyImg,
            'cat' => 'Despensa',
            'sub' => 'Harinas',
            'name' => "Producto {$id}",
            'pres' => 'Unidad',
            'price' => 12500,
            'image' => "assets/products/{$legacyImg}.jpg",
            'inventory' => 7,
            'active' => $active,
        ];
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory) || is_link($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($directory.DIRECTORY_SEPARATOR.$entry);
            }
        }

        @rmdir($directory);
    }
}
