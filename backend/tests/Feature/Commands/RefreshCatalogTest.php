<?php

namespace Tests\Feature\Commands;

use App\Services\CatalogSnapshotStore;
use App\Repositories\ProductSheetsRepository;
use App\Services\ProductSheetsException;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class RefreshCatalogTest extends TestCase
{
    private string $directory;
    private CatalogSnapshotStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'catalog-command-'.bin2hex(random_bytes(8));
        $this->store = new CatalogSnapshotStore($this->directory);
        $this->app->instance(CatalogSnapshotStore::class, $this->store);
        Cache::forget(CatalogSnapshotStore::CACHE_KEY);
    }

    protected function tearDown(): void
    {
        Cache::forget(CatalogSnapshotStore::CACHE_KEY);
        $this->removeDirectory($this->directory);

        parent::tearDown();
    }

    public function test_a_successful_refresh_replaces_snapshot_and_cache_only_after_a_valid_upstream_result(): void
    {
        $client = Mockery::mock(ProductSheetsRepository::class);
        $client->shouldReceive('all')->once()->andReturn([$this->upstreamProduct(3)]);
        $this->app->instance(ProductSheetsRepository::class, $client);

        $this->artisan('products:refresh-catalog')->assertExitCode(0);

        $expected = [$this->snapshotProduct(3)];
        $this->assertSame($expected, $this->store->read());
        $this->assertSame($expected, Cache::get(CatalogSnapshotStore::CACHE_KEY));
    }

    public function test_upstream_failure_preserves_the_previous_snapshot_and_cache(): void
    {
        $previous = [$this->snapshotProduct(2)];
        $this->store->writeAtomically($previous);
        $this->store->replaceCache($previous);

        $client = Mockery::mock(ProductSheetsRepository::class);
        $client->shouldReceive('all')->once()->andThrow(new ProductSheetsException(504));
        $this->app->instance(ProductSheetsRepository::class, $client);

        $this->artisan('products:refresh-catalog')->assertExitCode(1);

        $this->assertSame($previous, $this->store->read());
        $this->assertSame($previous, Cache::get(CatalogSnapshotStore::CACHE_KEY));
    }

    /** @return array<string, int|bool|string> */
    private function upstreamProduct(int $id): array
    {
        return [
            ...$this->snapshotProduct($id),
            'created_at' => '2026-09-18T00:00:00Z',
            'updated_at' => '2026-09-18T00:00:00Z',
            'revision' => 1,
        ];
    }

    /** @return array<string, int|bool|string> */
    private function snapshotProduct(int $id): array
    {
        return [
            'product_id' => $id,
            'category' => 'Despensa',
            'subcategory' => 'Harinas',
            'name' => "Producto {$id}",
            'presentation' => 'Unidad',
            'price_cop' => 12000,
            'inventory' => 7,
            'active' => true,
            'image_path' => 'assets/products/03.jpg',
            'legacy_img' => '03',
            'revision' => 1,
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
