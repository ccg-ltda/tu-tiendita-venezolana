<?php

namespace Tests\Unit\Services;

use App\Models\AdminAuditLog;
use App\Repositories\MySqlAdminAuditRepository;
use App\Services\AdminAuditService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AdminAuditServiceResourceLabelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp(); config()->set('database.connections.mysql', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']); DB::purge('mysql');
        Schema::connection('mysql')->create('admin_audit_logs', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('admin_id')->nullable(); $table->string('username'); $table->string('action'); $table->string('resource_type'); $table->string('resource_id'); $table->string('resource_label')->nullable(); $table->longText('before_json'); $table->longText('after_json'); $table->uuid('request_id'); $table->timestamp('created_at')->nullable(); });
    }

    public function test_persists_human_labels_from_the_event_snapshot_without_resource_lookups(): void
    {
        $service = new AdminAuditService(new MySqlAdminAuditRepository); $request = $this->request();
        $service->record($request, 'UPDATE', 'PRODUCT', 555, [], ['name' => 'Harina P.A.N. 1 kg']);
        $service->record($request, 'CREATE', 'CATEGORY', 2, [], ['name' => 'Alimentos']);
        $service->record($request, 'CREATE', 'SUBCATEGORY', 45, [], ['name' => 'Harinas']);
        $service->record($request, 'CREATE', 'COUPON', 8, [], ['code' => 'BIENVENIDO10']);
        $service->record($request, 'STATUS_CHANGE', 'ORDER', 'TTV-20261009-0001', [], ['reference' => 'TTV-20261009-0001']);

        $this->assertSame(['Harina P.A.N. 1 kg', 'Alimentos', 'Harinas', 'BIENVENIDO10', 'TTV-20261009-0001'], AdminAuditLog::query()->orderBy('id')->pluck('resource_label')->all());
        $page = (new MySqlAdminAuditRepository)->page(50, null, ['include_auth' => true]);
        $this->assertSame('Harina P.A.N. 1 kg', $page['items'][4]['resource_label']);
    }

    private function request(): Request
    {
        $request = Request::create('/api/admin/example', 'PATCH'); $request->setLaravelSession(app('session.store'));
        $request->session()->put(['admin_id' => 1, 'admin_username' => 'alejo.a', 'admin_name' => 'Alejandro Amado']);
        return $request;
    }
}
