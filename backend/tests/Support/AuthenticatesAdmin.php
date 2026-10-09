<?php

namespace Tests\Support;

use App\Models\Admin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

trait AuthenticatesAdmin
{
    protected function setUpAdminDatabase(): void
    {
        config()->set('database.connections.mysql', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::purge('mysql');
        Schema::connection('mysql')->create('admins', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('username')->unique();
            $table->string('password_hash'); $table->string('role'); $table->boolean('active');
            $table->dateTime('password_changed_at')->nullable(); $table->unsignedInteger('revision'); $table->timestamps();
        });
    }

    protected function authenticatedAdmin(int $id = 1, array $attributes = []): static
    {
        $admin = Admin::on('mysql')->updateOrCreate(['id' => $id], array_replace([
            'name' => 'Administrador de Prueba', 'username' => 'admin.test',
            'password_hash' => Hash::make('CorrectPassword12!'), 'role' => 'ADMIN',
            'active' => true, 'password_changed_at' => now('UTC'), 'revision' => 1,
        ], $attributes));

        return $this->withSession([
            'admin_authenticated' => true, 'admin_id' => $admin->id, 'admin_name' => $admin->name,
            'admin_username' => $admin->username, 'admin_role' => $admin->role, 'admin_revision' => $admin->revision,
        ]);
    }
}
