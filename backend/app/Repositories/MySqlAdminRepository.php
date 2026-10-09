<?php

namespace App\Repositories;

use App\Admin\AdminAccountNormalizer;
use App\Models\Admin;
use App\Services\PersistenceException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;

/** MySQL implementation of the established administrative-account contract. */
final class MySqlAdminRepository
{
    /** @return array<string,mixed>|null */
    public function findByAdminId(int $id): ?array { $admin = Admin::query()->find($id); return $admin === null ? null : $this->record($admin); }
    /** @return array<string,mixed>|null */
    public function findByUsername(string $username): ?array { $admin = Admin::query()->where('username', $username)->first(); return $admin === null ? null : $this->record($admin); }
    /** @return list<array<string,mixed>> */
    public function all(): array { return Admin::query()->orderBy('id')->get()->map(fn (Admin $admin): array => $this->record($admin))->all(); }
    /** @return list<array{id:int,name:string,username:string,active:bool}> */
    public function activityFilterOptions(): array
    {
        return Admin::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'username', 'active'])
            ->map(static fn (Admin $admin): array => ['id' => $admin->id, 'name' => $admin->name, 'username' => $admin->username, 'active' => $admin->active])
            ->all();
    }

    /** @param array<string,mixed> $attributes @return array<string,mixed> */
    public function create(array $attributes): array
    {
        try { $admin = Admin::query()->create($this->attributes($attributes, 1)); }
        catch (QueryException $exception) {
            if (str_contains((string) $exception->getCode(), '23000') || str_contains(strtolower($exception->getMessage()), 'duplicate')) throw new PersistenceException(422, 'DUPLICATE_ADMIN_USERNAME', $exception, 'Ya existe una cuenta administrativa con este usuario.');
            throw $exception;
        }
        return $this->record($admin->fresh());
    }

    /** @param array<string,mixed> $attributes @return array<string,mixed> */
    public function update(int $id, int $expectedRevision, array $attributes): array
    {
        $affected = Admin::query()->whereKey($id)->where('revision', $expectedRevision)->update($this->attributes($attributes, $expectedRevision + 1));
        if ($affected !== 1) {
            if (Admin::query()->whereKey($id)->doesntExist()) throw new PersistenceException(404, 'ADMIN_ACCOUNT_NOT_FOUND', null, 'La cuenta administrativa no existe.');
            throw new PersistenceException(409, 'ADMIN_ACCOUNT_REVISION_CONFLICT', null, 'La cuenta administrativa cambió; actualiza e intenta nuevamente.');
        }
        return $this->findByAdminId($id) ?? throw new \LogicException('Admin was not persisted.');
    }

    /** @param array<string,mixed> $account */
    public function passwordMatches(array $account, string $plainPassword): bool { return Hash::check($plainPassword, $account['password_hash']); }
    /** @param array<string,mixed> $account */
    public function passwordNeedsRehash(array $account): bool { return Hash::needsRehash($account['password_hash']); }

    /** @param array<string,mixed> $attributes @return array<string,mixed> */
    private function attributes(array $attributes, int $revision): array
    {
        $username = AdminAccountNormalizer::normalizeUsername($attributes['username'] ?? null);
        if ($username === null || !isset($attributes['name'], $attributes['password_hash']) || trim((string) $attributes['name']) === '') throw new PersistenceException(422, 'INVALID_ADMIN_ACCOUNT', null, 'La cuenta administrativa no es válida.');
        return ['name' => trim($attributes['name']), 'username' => $username, 'password_hash' => $attributes['password_hash'], 'role' => 'ADMIN', 'active' => (bool) $attributes['active'], 'password_changed_at' => $attributes['password_changed_at'] ?? null, 'revision' => $revision];
    }

    /** @return array<string,mixed> */
    private function record(Admin $admin): array
    {
        return ['admin_id' => $admin->id, 'name' => $admin->name, 'username' => $admin->username, 'password_hash' => $admin->password_hash, 'role' => $admin->role, 'active' => $admin->active, 'password_changed_at' => $admin->password_changed_at?->utc()->format('Y-m-d\\TH:i:s.v\\Z'), 'created_at' => $admin->created_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'), 'updated_at' => $admin->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'), 'revision' => $admin->revision];
    }
}
