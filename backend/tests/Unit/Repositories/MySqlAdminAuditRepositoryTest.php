<?php

namespace Tests\Unit\Repositories;

use App\Models\AdminAuditLog;
use App\Repositories\MySqlAdminAuditRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MySqlAdminAuditRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp(); config()->set('database.connections.mysql', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']); DB::purge('mysql');
        Schema::connection('mysql')->create('admin_audit_logs', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('admin_id')->nullable(); $table->string('username'); $table->string('action'); $table->string('resource_type'); $table->string('resource_id'); $table->string('resource_label')->nullable(); $table->longText('before_json'); $table->longText('after_json'); $table->uuid('request_id'); $table->timestamp('created_at')->nullable(); });
    }

    public function test_pages_newest_first_and_hides_auth_by_default(): void
    {
        $repo = new MySqlAdminAuditRepository; $this->append($repo, 'LOGIN', 'AUTH'); $this->append($repo, 'UPDATE', 'PRODUCT');
        $page = $repo->page(50, null, ['include_auth' => false]);
        $this->assertCount(1, $page['items']); $this->assertSame('UPDATE', $page['items'][0]['action']);
        $this->assertSame(['name' => 'before'], $page['items'][0]['before']);
    }

    public function test_filters_and_before_id_use_id_cursor(): void
    {
        $repo = new MySqlAdminAuditRepository; $this->append($repo, 'CREATE', 'PRODUCT'); $second = $this->append($repo, 'UPDATE', 'COUPON');
        $page = $repo->page(50, $second, ['include_auth' => true, 'resource_type' => 'PRODUCT']);
        $this->assertCount(1, $page['items']); $this->assertSame('PRODUCT', $page['items'][0]['resource_type']);
    }

    private function append(MySqlAdminAuditRepository $repo, string $action, string $resource): int { $repo->append(['admin_id' => 1, 'username' => 'admin.test', 'action' => $action, 'resource_type' => $resource, 'resource_id' => $resource === 'ORDER' ? 'TTV-1' : '1', 'before_json' => ['name' => 'before'], 'after_json' => ['name' => 'after'], 'request_id' => (string) \Illuminate\Support\Str::uuid(), 'created_at' => now('UTC')]); return (int) AdminAuditLog::query()->latest('id')->value('id'); }
}
