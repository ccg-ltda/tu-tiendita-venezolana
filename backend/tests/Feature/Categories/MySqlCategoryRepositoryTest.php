<?php

namespace Tests\Feature\Categories;

use App\Repositories\MySqlCategoryRepository;
use App\Repositories\MySqlProductRepository;
use App\Repositories\MySqlSubcategoryRepository;
use App\Services\PersistenceException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MySqlCategoryRepositoryTest extends TestCase
{
    private MySqlCategoryRepository $categories;
    private MySqlSubcategoryRepository $subcategories;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.mysql', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::purge('mysql');
        Schema::connection('mysql')->create('categories', function (Blueprint $table): void { $table->id(); $table->string('name')->unique(); $table->string('slug')->unique(); $table->boolean('active'); $table->unsignedInteger('sort_order'); $table->timestamps(); });
        Schema::connection('mysql')->create('subcategories', function (Blueprint $table): void { $table->id(); $table->foreignId('category_id'); $table->string('name'); $table->string('slug'); $table->boolean('active'); $table->unsignedInteger('sort_order'); $table->timestamps(); $table->unique(['category_id', 'name']); });
        Schema::connection('mysql')->create('products', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('category_id')->nullable(); $table->unsignedBigInteger('subcategory_id')->nullable(); $table->string('category'); $table->string('subcategory'); $table->string('name'); $table->string('presentation'); $table->unsignedInteger('price_cop'); $table->unsignedInteger('inventory'); $table->boolean('active'); $table->string('image_path')->nullable(); $table->string('legacy_img')->nullable(); $table->unsignedInteger('revision'); $table->timestamps(); });
        $this->categories = new MySqlCategoryRepository(); $this->subcategories = new MySqlSubcategoryRepository();
    }

    public function test_create_list_status_and_duplicate_category_rules(): void
    {
        $category = $this->categories->create(' Alimentos ');
        $this->assertSame('Alimentos', $category['name']); $this->assertTrue($category['active']);
        try { $this->categories->create('alimentos'); $this->fail('Expected duplicate category rejection.'); } catch (PersistenceException $error) { $this->assertSame('CATEGORY_DUPLICATE', $error->remoteCode()); }
        try { $this->categories->create('Promociones'); $this->fail('Expected virtual category rejection.'); } catch (PersistenceException $error) { $this->assertSame('VIRTUAL_CATEGORY_RESERVED', $error->remoteCode()); }
        $this->assertFalse($this->categories->setActive($category['id'], false)['active']);
    }

    public function test_subcategories_are_scoped_to_their_category_and_product_classification_is_validated(): void
    {
        $food = $this->categories->create('Alimentos'); $home = $this->categories->create('Hogar');
        $flours = $this->subcategories->create($food['id'], 'Harinas'); $cleaning = $this->subcategories->create($home['id'], 'Limpieza');
        try { $this->subcategories->create($food['id'], 'Harinas'); $this->fail('Expected duplicate subcategory rejection.'); } catch (PersistenceException $error) { $this->assertSame('SUBCATEGORY_DUPLICATE', $error->remoteCode()); }
        $products = new MySqlProductRepository($this->categories);
        try { $products->create(['name' => 'Producto', 'category_id' => $food['id'], 'subcategory_id' => $cleaning['id'], 'presentation' => 'Unidad', 'price_cop' => 1000, 'inventory' => 1, 'active' => true, 'image_path' => null, 'legacy_img' => null]); $this->fail('Expected mismatched classification rejection.'); } catch (PersistenceException $error) { $this->assertSame('INVALID_CLASSIFICATION', $error->remoteCode()); }
        $product = $products->create(['name' => 'Harina', 'category_id' => $food['id'], 'subcategory_id' => $flours['id'], 'presentation' => 'Unidad', 'price_cop' => 1000, 'inventory' => 1, 'active' => true, 'image_path' => null, 'legacy_img' => null]);
        $this->assertSame('Alimentos', $product['category']); $this->assertSame('Harinas', $product['subcategory']);
    }
}
