<?php

namespace Tests\Feature;

use Tests\Support\AuthenticatesAdmin;
use Tests\TestCase;

final class AdminSessionRevisionTest extends TestCase
{
    use AuthenticatesAdmin;

    public function test_a_session_with_a_stale_admin_revision_is_invalidated(): void
    {
        $this->setUpAdminDatabase();
        $this->authenticatedAdmin(1, ['revision' => 2])
            ->withSession([
                'admin_authenticated' => true,
                'admin_id' => 1,
                'admin_name' => 'Administrador de Prueba',
                'admin_username' => 'admin.test',
                'admin_role' => 'ADMIN',
                'admin_revision' => 1,
            ])
            ->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'No autenticado.');
    }
}
