<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Product;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('mysql')]
final class AdminUsersTest extends TestCase
{
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        $database = config('database.connections.mysql.database');
        if (! is_string($database) || ! str_ends_with($database, '_test')) {
            throw new \RuntimeException('Estas pruebas exigen una base de datos que termine en _test.');
        }
        Artisan::call('migrate', ['--database' => 'mysql', '--force' => true]);
        $this->tag = 'usr'.bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        Admin::query()->where('username', 'like', $this->tag.'%')->delete();
        Product::query()->where('name', 'like', $this->tag.'%')->delete();
        parent::tearDown();
    }

    private function asRole(string $role): static
    {
        $admin = Admin::query()->firstOrCreate(['username' => $this->tag.'-'.strtolower($role)], [
            'name' => $this->tag, 'password_hash' => Hash::make('CorrectPassword12!'),
            'role' => $role, 'active' => true, 'password_changed_at' => now('UTC'), 'revision' => 1,
        ]);

        return $this->withSession([
            'admin_authenticated' => true, 'admin_id' => $admin->id, 'admin_name' => $admin->name,
            'admin_username' => $admin->username, 'admin_role' => $admin->role, 'admin_revision' => $admin->revision,
        ]);
    }

    public function test_only_admin_manages_users_and_passwords_are_never_returned(): void
    {
        $this->asRole('DESPACHO')->getJson('/api/admin/users')->assertForbidden();
        $this->asRole('ENTREGA')->postJson('/api/admin/users', [])->assertForbidden();

        $response = $this->asRole('ADMIN')->postJson('/api/admin/users', [
            'name' => 'Ana Despacho', 'username' => $this->tag.'.ana', 'role' => 'DESPACHO', 'password' => 'UnaClaveLarga123',
        ])->assertCreated()->assertJsonPath('user.role', 'DESPACHO')->assertJsonMissingPath('user.password_hash');
        $this->assertStringNotContainsString('UnaClaveLarga123', $response->getContent());

        $list = $this->asRole('ADMIN')->getJson('/api/admin/users')->assertOk();
        $this->assertStringNotContainsString('password', $list->getContent());
    }

    public function test_rejects_short_passwords_bad_roles_and_duplicates(): void
    {
        $base = ['name' => 'Luis', 'username' => $this->tag.'.luis', 'role' => 'ENTREGA', 'password' => 'UnaClaveLarga123'];
        $this->asRole('ADMIN')->postJson('/api/admin/users', ['password' => 'corta'] + $base)->assertStatus(422);
        $this->asRole('ADMIN')->postJson('/api/admin/users', ['role' => 'DUENO'] + $base)->assertStatus(422);
        $this->asRole('ADMIN')->postJson('/api/admin/users', $base)->assertCreated();
        $this->asRole('ADMIN')->postJson('/api/admin/users', $base)->assertStatus(422);
    }

    public function test_an_admin_cannot_lock_themselves_out_and_can_deactivate_others(): void
    {
        $me = Admin::query()->where('username', $this->tag.'-admin')->first();
        $this->asRole('ADMIN');
        $me = Admin::query()->where('username', $this->tag.'-admin')->firstOrFail();
        $this->asRole('ADMIN')->patchJson("/api/admin/users/{$me->id}", ['active' => false])->assertStatus(422);
        $this->asRole('ADMIN')->patchJson("/api/admin/users/{$me->id}", ['role' => 'ENTREGA'])->assertStatus(422);

        $otherId = $this->asRole('ADMIN')->postJson('/api/admin/users', [
            'name' => 'Pedro', 'username' => $this->tag.'.pedro', 'role' => 'ENTREGA', 'password' => 'UnaClaveLarga123',
        ])->assertCreated()->json('user.id');
        $this->asRole('ADMIN')->patchJson("/api/admin/users/{$otherId}", ['active' => false, 'role' => 'DESPACHO'])
            ->assertOk()->assertJsonPath('user.active', false)->assertJsonPath('user.role', 'DESPACHO');
    }

    public function test_command_sets_the_same_inventory_to_every_product(): void
    {
        $a = Product::query()->create(['category' => 'P', 'subcategory' => 'P', 'name' => $this->tag.' A', 'presentation' => '1', 'price_cop' => 1000, 'inventory' => 0, 'active' => true, 'revision' => 1]);
        $b = Product::query()->create(['category' => 'P', 'subcategory' => 'P', 'name' => $this->tag.' B', 'presentation' => '1', 'price_cop' => 1000, 'inventory' => 7, 'active' => true, 'revision' => 1]);
        Artisan::call('inventory:set-all', ['amount' => 1000, '--force' => true, '--only-empty' => true]);
        $this->assertSame(1000, $a->fresh()->inventory);
        $this->assertSame(7, $b->fresh()->inventory, '--only-empty no toca los que ya tienen existencias');
    }
}
