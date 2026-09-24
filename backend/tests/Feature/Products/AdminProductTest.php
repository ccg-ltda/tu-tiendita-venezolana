<?php

namespace Tests\Feature\Products;

use App\Services\CatalogSnapshotStore;
use App\Repositories\ProductSheetsRepository;
use App\Services\ProductImageStore;
use Illuminate\Http\UploadedFile;
use Mockery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminProductTest extends TestCase
{
    private string $directory;
    private CatalogSnapshotStore $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'catalog-admin-'.bin2hex(random_bytes(8));
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

    public function test_an_unauthenticated_user_cannot_view_the_administrative_catalog(): void
    {
        $this->getJson('/api/admin/products')->assertUnauthorized();
    }

    public function test_an_admin_can_view_active_and_inactive_products_from_the_local_snapshot(): void
    {
        $this->catalog->writeAtomically([
            $this->product(20, true, 'assets/products/20.jpg', '20'),
            $this->product(2, false, '/storage/products/02.jpg', '01'),
        ]);
        Cache::forget(CatalogSnapshotStore::CACHE_KEY);
        Http::fake();

        $products = $this->withSession(['admin_authenticated' => true])
            ->getJson('/api/admin/products')
            ->assertOk()
            ->json('products');

        $this->assertSame([2, 20], array_column($products, 'id'));
        $this->assertSame($this->productFields(), array_keys($products[0]));
        $this->assertFalse($products[0]['active']);
        $this->assertTrue($products[1]['active']);
        Http::assertNothingSent();
    }

    public function test_an_admin_gets_a_generic_503_when_the_snapshot_is_missing_without_calling_apps_script(): void
    {
        Http::fake();

        $this->withSession(['admin_authenticated' => true])
            ->getJson('/api/admin/products')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '60');

        Http::assertNothingSent();
    }

    public function test_an_unauthenticated_user_cannot_access_product_write_endpoints(): void
    {
        $this->postJson('/api/admin/products', [])->assertUnauthorized();
        $this->patchJson('/api/admin/products/999999999', [])->assertUnauthorized();
        $this->patchJson('/api/admin/products/999999999/status', [])->assertUnauthorized();
    }

    public function test_an_authenticated_admin_validates_product_writes_before_calling_sheets(): void
    {
        Http::fake();

        $this->withSession(['admin_authenticated' => true])->postJson('/api/admin/products', [])->assertStatus(422);
        $this->withSession(['admin_authenticated' => true])->patchJson('/api/admin/products/999999999', [])->assertStatus(422);
        $this->withSession(['admin_authenticated' => true])->patchJson('/api/admin/products/999999999/status', [])->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_admin_can_create_a_product_and_write_through_the_snapshot(): void
    {
        $this->catalog->writeAtomically([]);
        $created = $this->product(21, true, '/assets/products/'.str_repeat('a', 32).'.jpg', '');
        $repository = Mockery::mock(ProductSheetsRepository::class);
        $repository->shouldReceive('create')->once()->andReturn($created);
        $this->app->instance(ProductSheetsRepository::class, $repository);

        $this->withSession(['admin_authenticated' => true])->postJson('/api/admin/products', [
            'name' => 'Producto 21', 'category' => 'Despensa', 'subcategory' => 'Harinas', 'presentation' => 'Unidad',
            'price' => 12000, 'inventory' => 7, 'active' => true,
        ])->assertOk()->assertJsonPath('product.id', 21)->assertJsonPath('catalog_refreshed', true);

        $this->assertSame(21, $this->catalog->read()[0]['product_id']);
    }

    public function test_admin_update_and_status_require_the_current_revision(): void
    {
        $this->catalog->writeAtomically([$this->product(2, true, '/assets/products/'.str_repeat('b', 32).'.jpg', '')]);
        $updated = $this->product(2, false, '/assets/products/'.str_repeat('b', 32).'.jpg', '');
        $updated['revision'] = 2;
        $repository = Mockery::mock(ProductSheetsRepository::class);
        $repository->shouldReceive('update')->once()->andReturn($updated);
        $repository->shouldReceive('setActive')->once()->andThrow(new \App\Services\ProductSheetsException(409, 'REVISION_CONFLICT'));
        $this->app->instance(ProductSheetsRepository::class, $repository);

        $this->withSession(['admin_authenticated' => true])->patchJson('/api/admin/products/2', [
            'name' => 'Producto actualizado', 'category' => 'Despensa', 'subcategory' => 'Harinas', 'presentation' => 'Unidad',
            'price' => 12000, 'inventory' => 6, 'expected_revision' => 1,
        ])->assertOk()->assertJsonPath('product.revision', 2);

        $this->withSession(['admin_authenticated' => true])->patchJson('/api/admin/products/2/status', [
            'active' => true, 'expected_revision' => 1,
        ])->assertStatus(409);
    }

    public function test_admin_update_with_image_returns_a_product_when_sync_is_successful(): void
    {
        $this->catalog->writeAtomically([$this->product(21, true, '/assets/products/'.str_repeat('b', 32).'.jpg', '')]);
        $path = '/assets/products/'.str_repeat('c', 32).'.jpg';
        $updated = $this->product(21, true, $path, '');
        $updated['revision'] = 2;
        $repository = Mockery::mock(ProductSheetsRepository::class);
        $repository->shouldReceive('update')->once()->andReturn($updated);
        $repository->shouldReceive('syncStatus')->andReturn('synced');
        $images = Mockery::mock(ProductImageStore::class);
        $images->shouldReceive('store')->once()->andReturn($path);
        $this->app->instance(ProductSheetsRepository::class, $repository);
        $this->app->instance(ProductImageStore::class, $images);

        $response = $this->withSession(['admin_authenticated' => true])->post('/api/admin/products/21', [
            '_method' => 'PATCH', 'name' => 'Producto 21', 'category' => 'Despensa', 'subcategory' => 'Harinas',
            'presentation' => 'Unidad', 'price' => 12000, 'inventory' => 8, 'expected_revision' => 1,
            'image' => $this->validPngUpload(),
        ]);

        $response->assertOk()->assertJsonPath('product.id', 21)->assertJsonPath('product.image', $path)->assertJsonPath('sync_status', 'synced');
        $this->assertSame($path, $this->catalog->read()[0]['image_path']);
    }

    public function test_admin_update_with_image_returns_pending_product_when_sync_is_pending(): void
    {
        $this->catalog->writeAtomically([$this->product(21, true, '/assets/products/'.str_repeat('b', 32).'.jpg', '')]);
        $path = '/assets/products/'.str_repeat('d', 32).'.webp';
        $updated = $this->product(21, true, $path, '');
        $updated['revision'] = 2;
        $repository = Mockery::mock(ProductSheetsRepository::class);
        $repository->shouldReceive('update')->once()->andReturn($updated);
        $repository->shouldReceive('syncStatus')->andReturn('pending');
        $images = Mockery::mock(ProductImageStore::class);
        $images->shouldReceive('store')->once()->andReturn($path);
        $this->app->instance(ProductSheetsRepository::class, $repository);
        $this->app->instance(ProductImageStore::class, $images);

        $response = $this->withSession(['admin_authenticated' => true])->post('/api/admin/products/21', [
            '_method' => 'PATCH', 'name' => 'Producto 21', 'category' => 'Despensa', 'subcategory' => 'Harinas',
            'presentation' => 'Unidad', 'price' => 12000, 'inventory' => 8, 'expected_revision' => 1,
            'image' => $this->validPngUpload(),
        ]);

        $response->assertStatus(202)->assertJsonPath('product.id', 21)->assertJsonPath('product.image', $path)->assertJsonPath('sync_status', 'pending');
        $this->assertSame($path, $this->catalog->read()[0]['image_path']);
    }

    /** @return list<string> */
    private function productFields(): array
    {
        return ['id', 'img', 'category', 'subcategory', 'name', 'presentation', 'price', 'image', 'inventory', 'active', 'revision'];
    }

    /** @return array<string, int|bool|string> */
    private function product(int $id, bool $active, string $imagePath, string $legacyImg): array
    {
        return [
            'product_id' => $id,
            'category' => 'Despensa',
            'subcategory' => 'Harinas',
            'name' => "Producto {$id}",
            'presentation' => 'Unidad',
            'price_cop' => 12000,
            'inventory' => 7,
            'active' => $active,
            'image_path' => $imagePath,
            'legacy_img' => $legacyImg,
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

    private function validPngUpload(): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        return UploadedFile::fake()->createWithContent('updated.png', $png === false ? '' : $png);
    }
}
