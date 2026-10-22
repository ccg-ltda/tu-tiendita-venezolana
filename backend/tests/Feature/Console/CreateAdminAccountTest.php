<?php

namespace Tests\Feature\Console;

use App\Models\Admin;
use Illuminate\Support\Facades\Hash;
use Tests\Support\AuthenticatesAdmin;
use Tests\TestCase;

final class CreateAdminAccountTest extends TestCase
{
    use AuthenticatesAdmin;

    public function test_local_environment_allows_admin_account_creation(): void
    {
        $this->setUpAdminDatabase();
        $this->setEnvironment('local');
        config()->set('services.admin_creation.allowed', false);

        $this->artisan('admin:create')
            ->expectsQuestion('Nombre', '  Administradora Local  ')
            ->expectsQuestion('Usuario', '  ADMIN.LOCAL  ')
            ->expectsQuestion('Contraseña', 'CorrectPassword12!')
            ->expectsQuestion('Confirmación de contraseña', 'CorrectPassword12!')
            ->expectsOutput('Administrador creado correctamente: admin.local')
            ->assertSuccessful();

        $this->assertDatabaseHas('admins', [
            'name' => 'Administradora Local',
            'username' => 'admin.local',
        ], 'mysql');
        $this->assertTrue(Hash::check('CorrectPassword12!', (string) Admin::on('mysql')->value('password_hash')));
    }

    public function test_production_blocks_admin_account_creation_when_not_explicitly_allowed(): void
    {
        $this->setUpAdminDatabase();
        $this->setEnvironment('production');
        config()->set('services.admin_creation.allowed', false);

        $this->artisan('admin:create')
            ->expectsOutput('La creación de administradores está deshabilitada en producción.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('admins', 0, 'mysql');
    }

    public function test_production_allows_admin_account_creation_when_explicitly_enabled(): void
    {
        $this->setUpAdminDatabase();
        $this->setEnvironment('production');
        config()->set('services.admin_creation.allowed', true);

        $this->artisan('admin:create')
            ->expectsQuestion('Nombre', 'Administradora Produccion')
            ->expectsQuestion('Usuario', 'admin.production')
            ->expectsQuestion('Contraseña', 'CorrectPassword12!')
            ->expectsQuestion('Confirmación de contraseña', 'CorrectPassword12!')
            ->expectsOutput('Administrador creado correctamente: admin.production')
            ->assertSuccessful();

        $this->assertDatabaseHas('admins', ['username' => 'admin.production'], 'mysql');
    }

    private function setEnvironment(string $environment): void
    {
        $this->app->instance('env', $environment);
        config()->set('app.env', $environment);
    }
}
