<?php

namespace Tests\Unit\Services;

use App\Repositories\MySqlAdminAuditRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MySqlAdminAuditStoreTest extends TestCase
{
    protected function setUp(): void { parent::setUp(); config()->set('database.connections.mysql', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']); DB::purge('mysql'); Schema::connection('mysql')->create('admin_audit_logs', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('admin_id')->nullable(); $table->string('username'); $table->string('action'); $table->string('resource_type'); $table->string('resource_id'); $table->string('resource_label')->nullable(); $table->longText('before_json'); $table->longText('after_json'); $table->uuid('request_id'); $table->timestamp('created_at')->nullable(); }); }
    public function test_appends_native_json_snapshot(): void { $repo = new MySqlAdminAuditRepository; $repo->append(['admin_id' => 1, 'username' => 'alejo.a', 'action' => 'STATUS_CHANGE', 'resource_type' => 'ORDER', 'resource_id' => 'TTV-4', 'before_json' => ['status' => 'PENDING'], 'after_json' => ['status' => 'READY'], 'request_id' => '123e4567-e89b-42d3-a456-426614174000', 'created_at' => now('UTC')]); $page = $repo->page(50, null, ['include_auth' => true]); $this->assertSame('TTV-4', $page['items'][0]['resource_id']); $this->assertSame(['status' => 'READY'], $page['items'][0]['after']); }
}
