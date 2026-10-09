<?php

namespace Tests\Feature\Products;

use App\Repositories\MySqlProductRepository;
use App\Services\ProductImageStore;
use Illuminate\Http\UploadedFile;
use Mockery;
use Illuminate\Support\Facades\Http;
use Tests\Support\AuthenticatesAdmin;
use Tests\TestCase;

class AdminProductTest extends TestCase
{
    use AuthenticatesAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminDatabase();

    }

    public function test_an_unauthenticated_user_cannot_view_the_administrative_catalog(): void
    {
        $this->getJson('/api/admin/products')->assertUnauthorized();
    }

    public function test_an_admin_can_view_active_and_inactive_products_from_mysql(): void
    {
        $rows = [
            $this->product(20, true, 'assets/products/20.jpg', '20'),
            $this->product(2, false, '/storage/products/02.jpg', '01'),
        ];
        $repository = Mockery::mock(MySqlProductRepository::class);
        $repository->shouldReceive('all')->once()->andReturn($rows);
        $this->app->instance(MySqlProductRepository::class, $repository);

        $products = $this->authenticatedAdmin()
            ->getJson('/api/admin/products')
            ->assertOk()
            ->json('products');

        $this->assertSame([2, 20], array_column($products, 'id'));
        $this->assertSame($this->productFields(), array_keys($products[0]));
        $this->assertFalse($products[0]['active']);
        $this->assertTrue($products[1]['active']);
    }

    public function test_an_admin_gets_a_generic_503_when_mysql_is_unavailable(): void
    {
        $repository = Mockery::mock(MySqlProductRepository::class);
        $repository->shouldReceive('all')->once()->andThrow(new \RuntimeException('database unavailable'));
        $this->app->instance(MySqlProductRepository::class, $repository);

        $this->authenticatedAdmin()
            ->getJson('/api/admin/products')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '60');

    }

    public function test_an_unauthenticated_user_cannot_access_product_write_endpoints(): void
    {
        $this->postJson('/api/admin/products', [])->assertUnauthorized();
        $this->patchJson('/api/admin/products/999999999', [])->assertUnauthorized();
        $this->patchJson('/api/admin/products/999999999/status', [])->assertUnauthorized();
    }

    public function test_an_authenticated_admin_validates_product_writes_before_reaching_mysql(): void
    {
        Http::fake();

        $this->authenticatedAdmin()->postJson('/api/admin/products', [])->assertStatus(422);
        $this->authenticatedAdmin()->patchJson('/api/admin/products/999999999', [])->assertStatus(422);
        $this->authenticatedAdmin()->patchJson('/api/admin/products/999999999/status', [])->assertStatus(422);

    }

    public function test_an_authenticated_admin_cannot_create_a_free_product(): void
    {
        $this->authenticatedAdmin()->postJson('/api/admin/products', [
            'name' => 'Producto sin precio', 'category_id' => 1, 'subcategory_id' => 2,
            'presentation' => 'Unidad', 'price' => 0, 'inventory' => 1, 'active' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('price');
    }

    public function test_admin_can_create_a_product_in_mysql(): void
    {
        $created = $this->product(21, true, '/assets/products/'.str_repeat('a', 32).'.jpg', '');
        $repository = Mockery::mock(MySqlProductRepository::class);
        $repository->shouldReceive('create')->once()->andReturn($created);
        $repository->shouldReceive('syncStatus')->once()->andReturn('synced');
        $this->app->instance(MySqlProductRepository::class, $repository);

        $this->authenticatedAdmin()->postJson('/api/admin/products', [
            'name' => 'Producto 21', 'category_id' => 1, 'subcategory_id' => 2, 'presentation' => 'Unidad',
            'price' => 12000, 'inventory' => 7, 'active' => true,
        ])->assertOk()->assertJsonPath('product.id', 21)->assertJsonPath('catalog_refreshed', true);

    }

    public function test_admin_update_and_status_require_the_current_revision(): void
    {
        $updated = $this->product(2, false, '/assets/products/'.str_repeat('b', 32).'.jpg', '');
        $updated['revision'] = 2;
        $repository = Mockery::mock(MySqlProductRepository::class);
        $repository->shouldReceive('update')->once()->andReturn($updated);
        $repository->shouldReceive('syncStatus')->once()->andReturn('synced');
        $repository->shouldReceive('setActive')->once()->andThrow(new \App\Services\PersistenceException(409, 'REVISION_CONFLICT'));
        $this->app->instance(MySqlProductRepository::class, $repository);

        $this->authenticatedAdmin()->patchJson('/api/admin/products/2', [
            'name' => 'Producto actualizado', 'category_id' => 1, 'subcategory_id' => 2, 'presentation' => 'Unidad',
            'price' => 12000, 'inventory' => 6, 'expected_revision' => 1,
        ])->assertOk()->assertJsonPath('product.revision', 2);

        $this->authenticatedAdmin()->patchJson('/api/admin/products/2/status', [
            'active' => true, 'expected_revision' => 1,
        ])->assertStatus(409);
    }

    public function test_admin_update_with_image_returns_a_product_when_sync_is_successful(): void
    {
        $path = '/assets/products/'.str_repeat('c', 32).'.jpg';
        $updated = $this->product(21, true, $path, '');
        $updated['revision'] = 2;
        $repository = Mockery::mock(MySqlProductRepository::class);
        $repository->shouldReceive('update')->once()->andReturn($updated);
        $repository->shouldReceive('syncStatus')->andReturn('synced');
        $images = Mockery::mock(ProductImageStore::class);
        $images->shouldReceive('store')->once()->andReturn($path);
        $this->app->instance(MySqlProductRepository::class, $repository);
        $this->app->instance(ProductImageStore::class, $images);

        $response = $this->authenticatedAdmin()->post('/api/admin/products/21', [
            '_method' => 'PATCH', 'name' => 'Producto 21', 'category_id' => 1, 'subcategory_id' => 2,
            'presentation' => 'Unidad', 'price' => 12000, 'inventory' => 8, 'expected_revision' => 1,
            'image' => $this->validPngUpload(),
        ]);

        $response->assertOk()->assertJsonPath('product.id', 21)->assertJsonPath('product.image', $path)->assertJsonPath('sync_status', 'synced');
    }

    public function test_admin_update_with_image_reports_mysql_as_synced(): void
    {
        $path = '/assets/products/'.str_repeat('d', 32).'.webp';
        $updated = $this->product(21, true, $path, '');
        $updated['revision'] = 2;
        $repository = Mockery::mock(MySqlProductRepository::class);
        $repository->shouldReceive('update')->once()->andReturn($updated);
        $repository->shouldReceive('syncStatus')->andReturn('synced');
        $images = Mockery::mock(ProductImageStore::class);
        $images->shouldReceive('store')->once()->andReturn($path);
        $this->app->instance(MySqlProductRepository::class, $repository);
        $this->app->instance(ProductImageStore::class, $images);

        $response = $this->authenticatedAdmin()->post('/api/admin/products/21', [
            '_method' => 'PATCH', 'name' => 'Producto 21', 'category_id' => 1, 'subcategory_id' => 2,
            'presentation' => 'Unidad', 'price' => 12000, 'inventory' => 8, 'expected_revision' => 1,
            'image' => $this->validPngUpload(),
        ]);

        $response->assertOk()->assertJsonPath('product.id', 21)->assertJsonPath('product.image', $path)->assertJsonPath('sync_status', 'synced');
    }

    /** @return list<string> */
    private function productFields(): array
    {
        return ['id', 'category_id', 'subcategory_id', 'img', 'category', 'subcategory', 'name', 'presentation', 'price', 'image', 'inventory', 'active', 'revision'];
    }

    /** @return array<string, int|bool|string> */
    private function product(int $id, bool $active, string $imagePath, string $legacyImg): array
    {
        return [
            'product_id' => $id,
            'category_id' => 1,
            'subcategory_id' => 2,
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

    private function validPngUpload(): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        return UploadedFile::fake()->createWithContent('updated.png', $png === false ? '' : $png);
    }
}
