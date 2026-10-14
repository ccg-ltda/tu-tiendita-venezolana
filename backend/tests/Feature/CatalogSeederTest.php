<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Subcategory;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

#[Group('mysql')]
final class CatalogSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSafeTestDatabase();

        Artisan::call('migrate:fresh', [
            '--database' => 'mysql',
            '--force' => true,
        ]);
    }

    public function test_database_seeder_creates_an_idempotent_and_coherent_catalog(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(9, Category::query()->count());
        $this->assertSame(44, Subcategory::query()->count());
        $this->assertSame(198, Product::query()->count());

        $this->assertSame(0, Product::query()
            ->whereNull('category_id')
            ->orWhereNotIn('category_id', Category::query()->select('id'))
            ->count());

        $this->assertSame(0, Product::query()
            ->whereNull('subcategory_id')
            ->orWhereNotIn('subcategory_id', Subcategory::query()->select('id'))
            ->count());

        $this->assertSame(0, Product::query()
            ->leftJoin('subcategories', 'subcategories.id', '=', 'products.subcategory_id')
            ->whereColumn('products.category_id', '!=', 'subcategories.category_id')
            ->count());

        $this->assertDatabaseMissing('categories', ['name' => 'Promociones'], 'mysql');
        $this->assertDatabaseMissing('categories', ['name' => 'Prueba 1'], 'mysql');

        $this->assertSeededImagesExist();
        $this->assertTransactionalTablesAreEmpty();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(9, Category::query()->count());
        $this->assertSame(44, Subcategory::query()->count());
        $this->assertSame(198, Product::query()->count());
        $this->assertTransactionalTablesAreEmpty();
    }

    private function assertSeededImagesExist(): void
    {
        $directory = dirname(base_path()).DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'products';

        foreach (Product::query()->get(['id', 'image_path', 'legacy_img']) as $product) {
            if ($product->image_path !== null) {
                $this->assertMatchesRegularExpression(
                    '#^/?assets/products/[A-Za-z0-9]+\\.(?:jpg|jpeg|png|webp)$#',
                    $product->image_path,
                    "Unexpected image_path for product {$product->id}.",
                );

                $filename = basename($product->image_path);
                $this->assertFileExists($directory.DIRECTORY_SEPARATOR.$filename, "Missing image_path for product {$product->id}.");
            }

            if ($product->legacy_img !== null) {
                $this->assertMatchesRegularExpression(
                    '#^[A-Za-z0-9]+$#',
                    $product->legacy_img,
                    "Unexpected legacy_img for product {$product->id}.",
                );

                $matches = glob($directory.DIRECTORY_SEPARATOR.$product->legacy_img.'.{jpg,jpeg,png,webp}', GLOB_BRACE);
                $this->assertNotEmpty($matches, "Missing legacy image for product {$product->id}.");
            }
        }
    }

    private function assertTransactionalTablesAreEmpty(): void
    {
        foreach ([
            'orders',
            'payment_attempts',
            'sessions',
            'admin_audit_logs',
            'notification_outbox',
            'payment_event_outbox',
        ] as $table) {
            $this->assertSame(0, DB::connection('mysql')->table($table)->count(), "Seeder unexpectedly populated {$table}.");
        }
    }

    private function assertSafeTestDatabase(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('CatalogSeederTest requires APP_ENV=testing.');
        }

        $database = config('database.connections.mysql.database');

        if (! is_string($database) || ! str_ends_with($database, '_test')) {
            throw new RuntimeException('CatalogSeederTest requires a MySQL database whose name ends in _test.');
        }
    }
}
