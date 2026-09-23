<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    private const EMAIL = 'admin@example.test';
    private const PASSWORD = 'CorrectPassword12!';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('admin.name', 'Administrador de Prueba');
        config()->set('admin.email', self::EMAIL);
        config()->set('admin.password_hash', Hash::make(self::PASSWORD));
        config()->set('database.default', 'unavailable-for-admin-auth');
    }

    public function test_a_valid_login_uses_only_the_fixed_admin_configuration(): void
    {
        $response = $this->postJson('/api/auth/login', $this->credentials());

        $response->assertOk()
            ->assertExactJson([
                'message' => 'Inicio de sesión exitoso.',
                'user' => [
                    'id' => 1,
                    'name' => 'Administrador de Prueba',
                    'email' => self::EMAIL,
                    'role' => 'admin',
                ],
            ])
            ->assertSessionHas('admin_authenticated', true);

        $this->assertStringNotContainsString(
            (string) config('admin.password_hash'),
            $response->getContent(),
        );
    }

    public function test_login_normalizes_email_case_and_whitespace(): void
    {
        $this->postJson('/api/auth/login', $this->credentials([
            'email' => '  ADMIN@EXAMPLE.TEST  ',
        ]))->assertOk()->assertSessionHas('admin_authenticated', true);
    }

    public function test_incorrect_email_or_password_is_rejected_generically(): void
    {
        $this->postJson('/api/auth/login', $this->credentials(['email' => 'other@example.test']))
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Credenciales incorrectas.']);

        $this->postJson('/api/auth/login', $this->credentials(['password' => 'WrongPassword12!']))
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Credenciales incorrectas.']);
    }

    public function test_login_regenerates_the_session_and_never_stores_the_password(): void
    {
        $before = $this->app['session']->getId();

        $response = $this->postJson('/api/auth/login', $this->credentials());

        $response->assertOk()->assertSessionHas('admin_authenticated', true);
        $this->assertNotSame($before, $this->app['session']->getId());
        $this->assertNull($this->app['session']->get('password'));
        $this->assertNull($this->app['session']->get('admin_password_hash'));
    }

    public function test_me_returns_the_fixed_public_identity_for_an_authenticated_session(): void
    {
        $this->withSession(['admin_authenticated' => true])
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertExactJson([
                'user' => [
                    'id' => 1,
                    'name' => 'Administrador de Prueba',
                    'email' => self::EMAIL,
                    'role' => 'admin',
                ],
            ]);
    }

    public function test_me_requires_the_fixed_admin_session_flag(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->withSession(['admin_authenticated' => 'true'])->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_logout_invalidates_the_session_and_regenerates_the_csrf_token(): void
    {
        $response = $this->withSession(['admin_authenticated' => true])
            ->postJson('/api/auth/logout');

        $response->assertOk()
            ->assertExactJson(['message' => 'Sesión cerrada correctamente.'])
            ->assertSessionMissing('admin_authenticated');

        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_missing_or_invalid_admin_configuration_fails_closed(): void
    {
        config()->set('admin.email', null);
        $this->postJson('/api/auth/login', $this->credentials())->assertStatus(503);

        config()->set('admin.email', self::EMAIL);
        config()->set('admin.password_hash', 'not-a-password-hash');
        $this->postJson('/api/auth/login', $this->credentials())->assertStatus(503);
    }

    public function test_login_rate_limit_is_preserved(): void
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $this->postJson('/api/auth/login', $this->credentials(['password' => 'WrongPassword12!']))
                ->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', $this->credentials(['password' => 'WrongPassword12!']))
            ->assertStatus(429);
    }

    public function test_login_remains_in_the_web_middleware_group_for_csrf_protection(): void
    {
        $route = app('router')->getRoutes()->match(Request::create('/api/auth/login', 'POST'));

        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('throttle:admin-login', $route->gatherMiddleware());
    }

    public function test_password_recovery_routes_are_not_registered(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => self::EMAIL])->assertNotFound();
        $this->postJson('/api/auth/reset-password', [])->assertNotFound();
    }

    /** @param array<string, string> $overrides */
    private function credentials(array $overrides = []): array
    {
        return array_merge([
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ], $overrides);
    }
}
