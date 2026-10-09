<?php

namespace Tests\Unit\Admin;

use App\Admin\AdminAccountContractException;
use App\Admin\AdminAccountNormalizer;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAccountNormalizerTest extends TestCase
{
    public function test_declares_the_administrator_persistence_contract(): void
    {
        $this->assertSame([
            'admin_id', 'name', 'username', 'password_hash', 'role', 'active',
            'password_changed_at', 'created_at', 'updated_at', 'revision',
        ], AdminAccountNormalizer::HEADERS);
    }

    public function test_normalizes_trimmed_lowercase_username_and_admin_role(): void
    {
        $account = AdminAccountNormalizer::normalize($this->account(['username' => '  ADMIN.TIENDA ', 'role' => ' admin ']));
        $this->assertSame('admin.tienda', $account['username']);
        $this->assertSame('ADMIN', $account['role']);
        $this->assertSame('cajero_1', AdminAccountNormalizer::normalizeUsername(' CAJERO_1 '));
        $this->assertSame('admin-2', AdminAccountNormalizer::normalizeUsername('ADMIN-2'));
    }

    public function test_rejects_invalid_usernames_role_revision_and_hash(): void
    {
        foreach ([['username' => ' '], ['username' => 'admin tienda'], ['username' => '@admin'], ['username' => 'a'], ['username' => str_repeat('a', 41)], ['role' => 'EDITOR'], ['revision' => 0], ['password_hash' => 'not-a-password-hash']] as $changes) {
            try { AdminAccountNormalizer::normalize($this->account($changes)); $this->fail('Expected invalid contract.'); }
            catch (AdminAccountContractException) { $this->assertTrue(true); }
        }
    }

    public function test_accepts_supported_active_values_and_valid_laravel_hash(): void
    {
        $this->assertTrue(AdminAccountNormalizer::normalize($this->account(['active' => 'TRUE']))['active']);
        $this->assertFalse(AdminAccountNormalizer::normalize($this->account(['active' => '0']))['active']);
        $hash = Hash::make('CorrectPassword12!');
        $account = AdminAccountNormalizer::normalize($this->account(['password_hash' => $hash]));
        $this->assertTrue(Hash::check('CorrectPassword12!', $account['password_hash']));
    }

    public function test_public_view_never_contains_password_hash(): void
    {
        $public = AdminAccountNormalizer::publicView($this->account());
        $this->assertArrayNotHasKey('password_hash', $public);
    }

    private function account(array $changes = []): array
    {
        return array_replace([
            'admin_id' => 1, 'name' => 'Administradora', 'username' => 'admin.tienda',
            'password_hash' => Hash::make('CorrectPassword12!'), 'role' => 'ADMIN', 'active' => true,
            'password_changed_at' => null, 'created_at' => '2026-10-07T12:00:00.000Z',
            'updated_at' => '2026-10-07T12:00:00.000Z', 'revision' => 1,
        ], $changes);
    }
}
