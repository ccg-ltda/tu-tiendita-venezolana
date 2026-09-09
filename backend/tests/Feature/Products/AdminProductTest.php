<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminProductTest extends TestCase
{
    use DatabaseTransactions;

    public function test_an_unauthenticated_user_cannot_view_the_administrative_catalog(): void
    {
        $this->getJson('/api/admin/products')->assertUnauthorized();
    }

    public function test_a_customer_cannot_view_the_administrative_catalog(): void
    {
        $this->actingAs($this->customer())->getJson('/api/admin/products')->assertForbidden();
    }

    public function test_an_admin_can_view_active_and_inactive_products_using_the_administrative_contract(): void
    {
        $activeProduct = $this->createProduct(active: true);
        $inactiveProduct = $this->createProduct(active: false);

        $response = $this->actingAs($this->admin())->getJson('/api/admin/products')->assertOk();
        $payload = $response->json();

        $this->assertSame(['products'], array_keys($payload));
        $ids = [];
        foreach ($payload['products'] as $product) {
            $this->assertSame($this->adminProductFields(), array_keys($product));
            $this->assertArrayNotHasKey('created_at', $product);
            $this->assertArrayNotHasKey('updated_at', $product);
            $ids[] = $product['id'];
        }

        $sortedIds = $ids;
        sort($sortedIds);
        $this->assertSame($sortedIds, $ids);
        $this->assertSame(true, collect($payload['products'])->firstWhere('id', $activeProduct->id)['active']);
        $this->assertSame(false, collect($payload['products'])->firstWhere('id', $inactiveProduct->id)['active']);
    }

    public function test_an_unauthenticated_user_cannot_create_a_product(): void
    {
        $this->postJson('/api/admin/products', $this->payload())->assertUnauthorized();
    }

    public function test_a_customer_cannot_create_a_product(): void
    {
        $this->actingAs($this->customer())->postJson('/api/admin/products', $this->payload())->assertForbidden();
    }

    public function test_an_unauthenticated_user_cannot_update_a_product(): void
    {
        $product = $this->createProduct();

        $this->patchJson("/api/admin/products/{$product->id}", ['name' => 'Updated'])->assertUnauthorized();
    }

    public function test_a_customer_cannot_update_a_product(): void
    {
        $product = $this->createProduct();

        $this->actingAs($this->customer())->patchJson("/api/admin/products/{$product->id}", ['name' => 'Updated'])->assertForbidden();
    }

    public function test_an_unauthenticated_or_customer_user_cannot_update_product_status(): void
    {
        $product = $this->createProduct();

        $this->patchJson("/api/admin/products/{$product->id}/status", ['active' => false])->assertUnauthorized();
        $this->actingAs($this->customer())->patchJson("/api/admin/products/{$product->id}/status", ['active' => false])->assertForbidden();
    }

    public function test_an_admin_can_create_a_product_with_a_valid_image_and_fixed_inventory(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->admin())->post('/api/admin/products', $this->payload([
            'id' => 999999999,
            'inventory' => 999,
            'image' => UploadedFile::fake()->create('product.jpg', 100, 'image/jpeg'),
        ]))->assertCreated();

        $storedImage = $response->json('product.image');
        $this->assertSame(['product'], array_keys($response->json()));
        $this->assertSame($this->adminProductFields(), array_keys($response->json('product')));
        $this->assertNotSame(999999999, $response->json('product.id'));
        $response->assertJsonPath('product.inventory', 0)->assertJsonPath('product.active', true);
        $this->assertStringStartsWith('/storage/products/', $storedImage);
        Storage::disk('public')->assertExists(substr($storedImage, strlen('/storage/')));
        $this->assertDatabaseHas('products', [
            'id' => $response->json('product.id'),
            'name' => 'New product',
            'price' => 2500,
            'image' => $storedImage,
            'inventory' => 0,
            'active' => true,
        ]);
    }

    public function test_an_admin_can_create_a_product_without_an_image(): void
    {
        $response = $this->actingAs($this->admin())->postJson('/api/admin/products', $this->payload())->assertCreated();

        $response->assertJsonPath('product.image', null)->assertJsonPath('product.img', null);
    }

    public function test_product_creation_validates_required_fields_and_negative_price(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/products', ['price' => 100])
            ->assertUnprocessable()->assertJsonValidationErrors(['category', 'subcategory', 'name', 'presentation']);
        $this->actingAs($admin)->postJson('/api/admin/products', $this->payload(['price' => -1]))
            ->assertUnprocessable()->assertJsonValidationErrors('price');
    }

    public function test_product_creation_rejects_non_images_and_files_larger_than_five_megabytes(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->actingAs($admin)->withHeader('Accept', 'application/json')->post('/api/admin/products', $this->payload([
            'image' => UploadedFile::fake()->create('not-an-image.jpg', 10, 'text/plain'),
        ]))->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->actingAs($admin)->withHeader('Accept', 'application/json')->post('/api/admin/products', $this->payload([
            'image' => UploadedFile::fake()->create('too-large.jpg', 5121, 'image/jpeg'),
        ]))->assertUnprocessable()->assertJsonValidationErrors('image');
    }

    public function test_an_admin_can_update_only_allowed_product_fields(): void
    {
        $product = $this->createProduct(true, 12);

        $response = $this->actingAs($this->admin())->patchJson("/api/admin/products/{$product->id}", [
            'category' => 'Updated category',
            'subcategory' => 'Updated subcategory',
            'name' => 'Updated product',
            'presentation' => 'Box',
            'price' => 4500,
            'id' => 1,
            'inventory' => 999,
            'active' => false,
        ])->assertOk();

        $response->assertJsonPath('product.name', 'Updated product')
            ->assertJsonPath('product.price', 4500)
            ->assertJsonPath('product.inventory', 12)
            ->assertJsonPath('product.active', true)
            ->assertJsonPath('product.image', 'assets/products/test.jpg');
        $this->assertSame($this->adminProductFields(), array_keys($response->json('product')));
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Updated product',
            'price' => 4500,
            'inventory' => 12,
            'active' => true,
        ]);
    }

    public function test_updating_without_a_new_image_preserves_the_existing_image(): void
    {
        $product = $this->createProduct();

        $this->actingAs($this->admin())->patchJson("/api/admin/products/{$product->id}", ['name' => 'Updated'])
            ->assertOk()->assertJsonPath('product.image', 'assets/products/test.jpg');
        $this->assertDatabaseHas('products', ['id' => $product->id, 'image' => 'assets/products/test.jpg']);
    }

    public function test_updating_a_missing_product_returns_not_found(): void
    {
        $this->actingAs($this->admin())->patchJson('/api/admin/products/999999999', ['name' => 'Missing'])->assertNotFound();
    }

    public function test_replacing_a_managed_image_updates_the_product_and_removes_the_previous_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/previous.jpg', 'previous');
        $product = $this->createProduct();
        $product->update(['image' => '/storage/products/previous.jpg']);

        $response = $this->actingAs($this->admin())->patch("/api/admin/products/{$product->id}", [
            'image' => UploadedFile::fake()->create('replacement.png', 100, 'image/png'),
        ])->assertOk();

        $storedImage = $response->json('product.image');
        $this->assertStringStartsWith('/storage/products/', $storedImage);
        $this->assertNotSame('/storage/products/previous.jpg', $storedImage);
        Storage::disk('public')->assertMissing('products/previous.jpg');
        Storage::disk('public')->assertExists(substr($storedImage, strlen('/storage/')));
    }

    public function test_a_method_spoofed_patch_receives_and_replaces_an_uploaded_image(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/previous.jpg', 'previous');
        $product = $this->createProduct(true, 12);
        $product->update(['image' => '/storage/products/previous.jpg']);

        $response = $this->actingAs($this->admin())->post("/api/admin/products/{$product->id}", [
            '_method' => 'PATCH',
            'name' => 'Updated through spoofing',
            'price' => 4500,
            'image' => UploadedFile::fake()->create('replacement.png', 100, 'image/png'),
        ])->assertOk();

        $storedImage = $response->json('product.image');
        $response->assertJsonPath('product.name', 'Updated through spoofing')
            ->assertJsonPath('product.price', 4500)
            ->assertJsonPath('product.inventory', 12)
            ->assertJsonPath('product.active', true);
        $this->assertNotSame('/storage/products/previous.jpg', $storedImage);
        Storage::disk('public')->assertMissing('products/previous.jpg');
        Storage::disk('public')->assertExists(substr($storedImage, strlen('/storage/')));
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Updated through spoofing',
            'price' => 4500,
            'image' => $storedImage,
            'inventory' => 12,
            'active' => true,
        ]);
    }

    public function test_replacing_a_legacy_image_never_deletes_its_legacy_path(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('assets/products/legacy.jpg', 'legacy');
        $product = $this->createProduct();
        $product->update(['image' => 'assets/products/legacy.jpg']);

        $this->actingAs($this->admin())->patch("/api/admin/products/{$product->id}", [
            'image' => UploadedFile::fake()->create('replacement.webp', 100, 'image/webp'),
        ])->assertOk();

        Storage::disk('public')->assertExists('assets/products/legacy.jpg');
    }

    public function test_an_admin_can_deactivate_and_reactivate_a_product_without_changing_other_fields(): void
    {
        $product = $this->createProduct();
        $original = $product->only(['name', 'price', 'inventory']);
        $admin = $this->admin();

        $this->actingAs($admin)->patchJson("/api/admin/products/{$product->id}/status", [
            'active' => false,
            'name' => 'Must not change',
            'price' => 1,
            'inventory' => 999,
        ])->assertOk()->assertJsonPath('product.active', false);

        $product->refresh();
        $this->assertSame($original['name'], $product->name);
        $this->assertSame($original['price'], $product->price);
        $this->assertSame($original['inventory'], $product->inventory);

        $adminProducts = $this->actingAs($admin)->getJson('/api/admin/products')->assertOk()->json('products');
        $this->assertSame(false, collect($adminProducts)->firstWhere('id', $product->id)['active']);
        $this->assertNull(collect($this->getJson('/api/products')->assertOk()->json('products'))->firstWhere('id', $product->id));

        $this->actingAs($admin)->patchJson("/api/admin/products/{$product->id}/status", ['active' => true])
            ->assertOk()->assertJsonPath('product.active', true);
    }

    public function test_product_status_requires_a_boolean_active_value(): void
    {
        $product = $this->createProduct();

        $this->actingAs($this->admin())->patchJson("/api/admin/products/{$product->id}/status", ['active' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors('active');
    }

    public function test_the_public_catalog_keeps_its_legacy_contract(): void
    {
        $product = $this->createProduct();

        $publicProduct = collect($this->getJson('/api/products')->assertOk()->json('products'))->firstWhere('id', $product->id);

        $this->assertSame($this->publicProductFields(), array_keys($publicProduct));
        $this->assertArrayNotHasKey('created_at', $publicProduct);
        $this->assertArrayNotHasKey('updated_at', $publicProduct);
        $this->assertArrayNotHasKey('category', $publicProduct);
        $this->assertArrayNotHasKey('subcategory', $publicProduct);
        $this->assertArrayNotHasKey('presentation', $publicProduct);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => 'customer']);
    }

    private function createProduct(bool $active = true, int $inventory = 20): Product
    {
        return Product::query()->create([
            'img' => 'test-image',
            'category' => 'Test category',
            'subcategory' => 'Test subcategory',
            'name' => $active ? 'Active test product' : 'Inactive test product',
            'presentation' => 'Unit',
            'price' => 1000,
            'image' => 'assets/products/test.jpg',
            'inventory' => $inventory,
            'active' => $active,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'category' => 'New category',
            'subcategory' => 'New subcategory',
            'name' => 'New product',
            'presentation' => 'Unit',
            'price' => 2500,
        ], $overrides);
    }

    /** @return list<string> */
    private function adminProductFields(): array
    {
        return ['id', 'img', 'category', 'subcategory', 'name', 'presentation', 'price', 'image', 'inventory', 'active'];
    }

    /** @return list<string> */
    private function publicProductFields(): array
    {
        return ['id', 'img', 'cat', 'sub', 'name', 'pres', 'price', 'image', 'inventory', 'active'];
    }
}
