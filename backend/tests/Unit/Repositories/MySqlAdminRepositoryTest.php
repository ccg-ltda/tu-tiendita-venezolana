<?php

namespace Tests\Unit\Repositories;

use App\Models\Admin;
use App\Repositories\MySqlAdminRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MySqlAdminRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.mysql', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::purge('mysql');
        Schema::connection('mysql')->create('admins', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('username')->unique(); $table->string('password_hash');
            $table->string('role'); $table->boolean('active'); $table->dateTime('password_changed_at')->nullable();
            $table->unsignedInteger('revision'); $table->timestamps();
        });
    }

    public function test_creates_normalized_admin_without_exposing_hash_in_model_serialization(): void
    {
        $saved = (new MySqlAdminRepository)->create($this->attributes(['username' => 'admin.test']));
        $this->assertSame('admin.test', $saved['username']);
        $this->assertSame(1, $saved['revision']);
        $this->assertFalse(array_key_exists('password_hash', Admin::query()->firstOrFail()->toArray()));
    }

    public function test_updates_with_revision_and_rejects_stale_revision(): void
    {
        $repository = new MySqlAdminRepository; $saved = $repository->create($this->attributes());
        $updated = $repository->update($saved['admin_id'], 1, $this->attributes(['name' => 'Nuevo nombre']));
        $this->assertSame(2, $updated['revision']);
        $this->expectException(\App\Services\PersistenceException::class);
        $repository->update($saved['admin_id'], 1, $this->attributes());
    }

    /** @return array<string,mixed> */
    private function attributes(array $changes = []): array
    {
        return array_replace(['name' => 'Administrador', 'username' => 'admin.test', 'password_hash' => Hash::make('CorrectPassword12!'), 'role' => 'ADMIN', 'active' => true, 'password_changed_at' => now('UTC')], $changes);
    }
}
