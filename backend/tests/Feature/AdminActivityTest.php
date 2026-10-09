<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Repositories\MySqlAdminAuditRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AuthenticatesAdmin;
use Tests\TestCase;

final class AdminActivityTest extends TestCase
{
    use AuthenticatesAdmin;
    protected function setUp(): void
    {
        parent::setUp(); config()->set('database.connections.mysql', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']); DB::purge('mysql');
        Schema::connection('mysql')->create('admins', function (Blueprint $table): void { $table->id(); $table->string('name'); $table->string('username')->unique(); $table->string('password_hash'); $table->string('role'); $table->boolean('active'); $table->dateTime('password_changed_at')->nullable(); $table->unsignedInteger('revision'); $table->timestamps(); });
        Schema::connection('mysql')->create('admin_audit_logs', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('admin_id')->nullable(); $table->string('username'); $table->string('action'); $table->string('resource_type'); $table->string('resource_id'); $table->string('resource_label')->nullable(); $table->longText('before_json'); $table->longText('after_json'); $table->uuid('request_id'); $table->timestamp('created_at')->nullable(); });
        Admin::query()->create(['id' => 1, 'name' => 'Administrador', 'username' => 'admin.test', 'password_hash' => Hash::make('CorrectPassword12!'), 'role' => 'ADMIN', 'active' => true, 'revision' => 1]);
        Admin::query()->create(['id' => 2, 'name' => 'Bea Admin', 'username' => 'bea.admin', 'password_hash' => Hash::make('CorrectPassword12!'), 'role' => 'ADMIN', 'active' => true, 'revision' => 1]);
    }

    public function test_endpoint_requires_an_administrator(): void { $this->getJson('/api/admin/audit')->assertUnauthorized(); }

    public function test_administrator_filter_options_are_available_without_audit_records_and_never_expose_password_hashes(): void
    {
        $this->admin()->getJson('/api/admin/audit/administrators')
            ->assertOk()
            ->assertJsonPath('administrators.0.id', 1)
            ->assertJsonPath('administrators.1.username', 'bea.admin')
            ->assertJsonMissingPath('administrators.0.password_hash');
    }

    public function test_default_excludes_auth_and_returns_newest_records_first(): void
    {
        $this->append(1, 'CREATE', 'PRODUCT'); $this->append(2, 'LOGIN', 'AUTH'); $this->append(3, 'UPDATE', 'COUPON');
        $this->admin()->getJson('/api/admin/audit')->assertOk()->assertJsonPath('items.0.audit_id', 3)->assertJsonPath('items.1.audit_id', 1)->assertJsonCount(2, 'items')->assertJsonPath('has_more', false);
    }

    public function test_limit_before_id_filters_dates_and_sanitizes_payloads(): void
    {
        foreach (range(1, 101) as $id) $this->append($id, 'UPDATE', 'PRODUCT');
        $this->admin()->getJson('/api/admin/audit')->assertOk()->assertJsonCount(50, 'items')->assertJsonPath('items.0.audit_id', 101)->assertJsonPath('next_before_id', 52);
        $this->admin()->getJson('/api/admin/audit?limit=999')->assertOk()->assertJsonCount(100, 'items')->assertJsonPath('items.0.audit_id', 101)->assertJsonPath('next_before_id', 2);
        $this->admin()->getJson('/api/admin/audit?before_id=51&limit=2')->assertOk()->assertJsonPath('items.0.audit_id', 50)->assertJsonPath('next_before_id', 49);
        $this->append(102, 'STATUS_CHANGE', 'ORDER', ['admin_id' => 2, 'username' => 'bea.admin', 'resource_id' => 'TTV-102', 'resource_label' => 'TTV-102', 'before_json' => ['status' => 'PENDING', 'password_hash' => 'never'], 'after_json' => ['status' => 'PROCESSING', 'token' => 'never']]);
        $fixture = AdminAuditLog::query()->findOrFail(102);
        $this->assertSame(2, $fixture->admin_id); $this->assertSame('bea.admin', $fixture->username); $this->assertSame('STATUS_CHANGE', $fixture->action); $this->assertSame('ORDER', $fixture->resource_type); $this->assertSame('TTV-102', $fixture->resource_id); $this->assertSame('2026-10-07 12:00:00', $fixture->getRawOriginal('created_at'));
        $direct = (new MySqlAdminAuditRepository)->page(50, null, ['admin_id' => 2, 'resource_type' => 'ORDER', 'action' => 'STATUS_CHANGE', 'date_from' => '2026-10-07', 'date_to' => '2026-10-07', 'include_auth' => false]);
        $this->assertSame('TTV-102', $direct['items'][0]['resource_id']); $this->assertSame('TTV-102', $direct['items'][0]['resource_label']);
        $this->admin()->getJson('/api/admin/audit?admin_id=2&resource_type=ORDER&action=STATUS_CHANGE&date_from=2026-10-07&date_to=2026-10-07')->assertOk()->assertJsonPath('items.0.resource_id', 'TTV-102')->assertJsonPath('items.0.resource_label', 'TTV-102')->assertJsonMissingPath('items.0.before.password_hash')->assertJsonMissingPath('items.0.after.token');
        $this->append(103, 'LOGIN', 'AUTH');
        $this->admin()->getJson('/api/admin/audit?include_auth=1&resource_type=AUTH')->assertOk()->assertJsonPath('items.0.action', 'LOGIN');
    }

    private function admin() { return $this->authenticatedAdmin(); }
    /** @param array<string,mixed> $changes */
    private function append(int $id, string $action, string $resource, array $changes = []): void { $record = new AdminAuditLog; $record->forceFill(array_replace(['id' => $id, 'admin_id' => 1, 'username' => 'admin.test', 'action' => $action, 'resource_type' => $resource, 'resource_id' => (string) $id, 'before_json' => [], 'after_json' => [], 'created_at' => '2026-10-07 12:00:00', 'request_id' => sprintf('123e4567-e89b-42d3-a456-%012d', $id)], $changes)); $record->save(); }
}
