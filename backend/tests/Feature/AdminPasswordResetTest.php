<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AdminPasswordResetTest extends TestCase
{
    use DatabaseTransactions;

    private const OLD_PASSWORD = 'PreviousPassword12';
    private const NEW_PASSWORD = 'UpdatedPassword12';

    public function test_an_admin_can_reset_its_password_and_invalidates_existing_sessions(): void
    {
        $admin = $this->createUser('admin');
        $admin->forceFill(['remember_token' => 'previous-remember-token'])->save();
        $previousRememberToken = $admin->remember_token;
        $token = Password::broker()->createToken($admin);
        $sessionTable = (string) config('session.table', 'sessions');

        DB::table($sessionTable)->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => 'test-session',
            'last_activity' => time(),
        ]);

        $this->resetRequest($this->validPayload($admin, $token), '10.0.0.1')
            ->assertOk()
            ->assertExactJson([
                'message' => 'Contraseña actualizada correctamente.',
            ]);

        $admin->refresh();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $admin->password));
        $this->assertFalse(Hash::check(self::OLD_PASSWORD, $admin->password));
        $this->assertNotSame($previousRememberToken, $admin->remember_token);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $admin->email]);
        $this->assertDatabaseMissing($sessionTable, ['user_id' => $admin->id]);

        $this->resetRequest($this->validPayload($admin, $token), '10.0.0.1')
            ->assertStatus(422)
            ->assertExactJson([
                'message' => 'El enlace de recuperación no es válido o ha expirado.',
            ]);
    }

    public function test_an_invalid_or_expired_token_does_not_change_the_password(): void
    {
        $admin = $this->createUser('admin');

        $this->resetRequest($this->validPayload($admin, Str::random(64)), '10.0.0.2')
            ->assertStatus(422)
            ->assertExactJson([
                'message' => 'El enlace de recuperación no es válido o ha expirado.',
            ]);

        $expiredToken = Password::broker()->createToken($admin);

        DB::table('password_reset_tokens')
            ->where('email', $admin->email)
            ->update(['created_at' => now()->subMinutes(61)]);

        $this->resetRequest($this->validPayload($admin, $expiredToken), '10.0.0.2')
            ->assertStatus(422)
            ->assertExactJson([
                'message' => 'El enlace de recuperación no es válido o ha expirado.',
            ]);

        $admin->refresh();
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $admin->password));
    }

    public function test_password_confirmation_and_minimum_length_are_validated(): void
    {
        $admin = $this->createUser('admin');

        $this->resetRequest([
            'email' => $admin->email,
            'token' => Str::random(64),
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => 'DifferentPassword12',
        ], '10.0.0.3')
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->resetRequest([
            'email' => $admin->email,
            'token' => Str::random(64),
            'password' => 'short',
            'password_confirmation' => 'short',
        ], '10.0.0.3')
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_a_customer_cannot_use_the_administrative_reset_endpoint(): void
    {
        $customer = $this->createUser('customer');
        $token = Password::broker()->createToken($customer);

        $this->resetRequest($this->validPayload($customer, $token), '10.0.0.4')
            ->assertStatus(422)
            ->assertExactJson([
                'message' => 'El enlace de recuperación no es válido o ha expirado.',
            ]);

        $customer->refresh();
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $customer->password));
    }

    private function createUser(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'password' => self::OLD_PASSWORD,
        ]);
    }

    private function validPayload(User $user, string $token): array
    {
        return [
            'email' => $user->email,
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ];
    }

    private function resetRequest(array $payload, string $ipAddress): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ipAddress])
            ->postJson('/api/auth/reset-password', $payload);
    }
}
