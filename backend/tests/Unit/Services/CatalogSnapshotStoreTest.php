<?php

namespace Tests\Unit\Services;

use App\Services\CatalogSnapshotException;
use App\Services\CatalogSnapshotStore;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CatalogSnapshotStoreTest extends TestCase
{
    private string $directory;
    private CatalogSnapshotStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'catalog-store-'.bin2hex(random_bytes(8));
        $this->store = new CatalogSnapshotStore($this->directory);
        Cache::forget(CatalogSnapshotStore::CACHE_KEY);
    }

    protected function tearDown(): void
    {
        Cache::forget(CatalogSnapshotStore::CACHE_KEY);
        $this->removeDirectory($this->directory);

        parent::tearDown();
    }

    public function test_it_writes_a_versioned_snapshot_and_reads_it_back(): void
    {
        $document = $this->store->writeAtomically([$this->product(2)]);

        $this->assertSame(2, $document['schema_version']);
        $this->assertSame(1, $document['product_count']);
        $this->assertTrue($this->store->exists());
        $this->assertMatchesRegularExpression('/\.\d{3}Z$/', $document['generated_at']);
        $this->assertSame([$this->product(2)], $this->store->read());
    }

    public function test_cache_miss_reads_the_snapshot_then_the_valid_cache_is_reused(): void
    {
        $this->store->writeAtomically([$this->product(2)]);
        Cache::forget(CatalogSnapshotStore::CACHE_KEY);

        $this->assertSame([$this->product(2)], $this->store->read());
        @unlink($this->directory.DIRECTORY_SEPARATOR.'products.json');
        $this->assertSame([$this->product(2)], $this->store->read());
    }

    public function test_replacing_an_existing_snapshot_keeps_a_complete_new_document(): void
    {
        $this->store->writeAtomically([$this->product(2)]);
        $this->store->writeAtomically([$this->product(3)]);
        Cache::forget(CatalogSnapshotStore::CACHE_KEY);

        $this->assertSame([$this->product(3)], $this->store->read());
        $this->assertSame([], glob($this->directory.DIRECTORY_SEPARATOR.'*.backup.*') ?: []);
    }

    public function test_it_rejects_invalid_schema_count_and_duplicate_ids(): void
    {
        mkdir($this->directory, 0750, true);
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.'products.json', json_encode([
            'schema_version' => 1,
            'generated_at' => '2026-09-22T12:00:00.000Z',
            'product_count' => 1,
            'products' => [$this->product(2)],
        ], JSON_THROW_ON_ERROR));
        $this->expectException(CatalogSnapshotException::class);

        $this->store->read();
    }

    public function test_it_rejects_duplicate_products_before_replacing_the_previous_snapshot(): void
    {
        $this->store->writeAtomically([$this->product(2)]);

        $this->expectException(CatalogSnapshotException::class);
        try {
            $this->store->writeAtomically([$this->product(2), $this->product(2)]);
        } finally {
            Cache::forget(CatalogSnapshotStore::CACHE_KEY);
            $this->assertSame([$this->product(2)], $this->store->read());
        }
    }

    public function test_a_write_failure_keeps_the_previous_snapshot_and_removes_temporary_files(): void
    {
        $this->store->writeAtomically([$this->product(2)]);
        $blockedStore = new CatalogSnapshotStore($this->directory.DIRECTORY_SEPARATOR.'products.json');

        try {
            $blockedStore->writeAtomically([$this->product(3)]);
            $this->fail('Expected the invalid storage directory to fail.');
        } catch (CatalogSnapshotException) {
            Cache::forget(CatalogSnapshotStore::CACHE_KEY);
            $this->assertSame([$this->product(2)], $this->store->read());
            $this->assertSame([], glob($this->directory.DIRECTORY_SEPARATOR.'*.tmp') ?: []);
        }
    }

    public function test_a_symlinked_catalog_directory_is_rejected_when_the_platform_allows_symlinks(): void
    {
        $target = $this->directory.'-target';
        $link = $this->directory.'-link';
        mkdir($target, 0750, true);

        if (! function_exists('symlink') || ! @symlink($target, $link)) {
            $this->addToAssertionCount(1);

            return;
        }

        try {
            (new CatalogSnapshotStore($link))->writeAtomically([$this->product(2)]);
            $this->fail('Expected an unsafe symlink to be rejected.');
        } catch (CatalogSnapshotException) {
            $this->addToAssertionCount(1);
        } finally {
            @unlink($link);
            @rmdir($target);
        }
    }

    /** @return array<string, int|bool|string> */
    private function product(int $id): array
    {
        return [
            'product_id' => $id,
            'category' => 'Despensa',
            'subcategory' => 'Harinas',
            'name' => "Producto {$id}",
            'presentation' => 'Unidad',
            'price_cop' => 12500,
            'inventory' => 7,
            'active' => true,
            'image_path' => 'assets/products/02.jpg',
            'legacy_img' => '02',
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
