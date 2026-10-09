<?php

namespace Tests\Feature\Categories;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AuthenticatesAdmin;
use Tests\TestCase;

/**
 * HTTP contract coverage for category administration. The SQL integration suite
 * owns the migration-backed duplicate and FK assertions.
 */
final class AdminCategoryEndpointTest extends TestCase
{
    use AuthenticatesAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminDatabase();
        $schema = Schema::connection('mysql');
        $schema->create('categories', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('slug'); $table->boolean('active'); $table->unsignedInteger('sort_order'); $table->timestamps();
        });
        $schema->create('subcategories', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('category_id'); $table->string('name'); $table->string('slug'); $table->boolean('active'); $table->unsignedInteger('sort_order'); $table->timestamps();
        });
        $schema->create('products', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('subcategory_id')->nullable(); $table->string('subcategory')->nullable(); $table->timestamps();
        });
        $schema->create('admin_audit_logs', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('admin_id')->nullable(); $table->string('username'); $table->string('action'); $table->string('resource_type'); $table->string('resource_id'); $table->string('resource_label')->nullable(); $table->longText('before_json'); $table->longText('after_json'); $table->uuid('request_id'); $table->timestamp('created_at')->nullable();
        });
    }

    public function test_category_endpoints_require_an_authenticated_administrator(): void
    {
        $this->getJson('/api/admin/categories')->assertUnauthorized();
        $this->postJson('/api/admin/categories', ['name' => 'Alimentos'])->assertUnauthorized();
        $this->postJson('/api/admin/categories/1/subcategories', ['name' => 'Harinas'])->assertUnauthorized();
    }

    public function test_category_and_subcategory_payloads_require_a_name(): void
    {
        $this->authenticatedAdmin()->postJson('/api/admin/categories', [])->assertStatus(422);
        $this->authenticatedAdmin()->postJson('/api/admin/categories/1/subcategories', [])->assertStatus(422);
    }

    public function test_subcategory_changes_preserve_real_before_and_after_audit_snapshots(): void
    {
        DB::connection('mysql')->table('categories')->insert(['id' => 1, 'name' => 'Alimentos', 'slug' => 'alimentos', 'active' => true, 'sort_order' => 1, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        DB::connection('mysql')->table('subcategories')->insert(['id' => 1, 'category_id' => 1, 'name' => 'Granos', 'slug' => 'granos', 'active' => true, 'sort_order' => 1, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

        $this->authenticatedAdmin()->patchJson('/api/admin/subcategories/1', ['name' => 'Granos integrales'])->assertOk();
        $update = DB::connection('mysql')->table('admin_audit_logs')->where('action', 'UPDATE')->first();
        $this->assertSame('Granos', json_decode($update->before_json, true)['name']);
        $this->assertSame('Granos integrales', json_decode($update->after_json, true)['name']);

        $this->authenticatedAdmin()->patchJson('/api/admin/subcategories/1/status', ['active' => false])->assertOk();
        $status = DB::connection('mysql')->table('admin_audit_logs')->where('action', 'DEACTIVATE')->first();
        $this->assertTrue(json_decode($status->before_json, true)['active']);
        $this->assertFalse(json_decode($status->after_json, true)['active']);
    }
}
