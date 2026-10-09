<?php

namespace App\Admin;

use DateTimeImmutable;
use Illuminate\Support\Facades\Hash;

final class AdminAccountNormalizer
{
    public const HEADERS = ['admin_id','name','username','password_hash','role','active','password_changed_at','created_at','updated_at','revision'];

    /** @return array{admin_id:int,name:string,username:string,password_hash:string,role:'ADMIN',active:bool,password_changed_at:string|null,created_at:string,updated_at:string,revision:int} */
    public static function normalize(mixed $account): array
    {
        if (! is_array($account)) throw new AdminAccountContractException('Admin account must be an array.');
        $id = self::integer($account['admin_id'] ?? null, 1);
        $name = self::name($account['name'] ?? null);
        $username = self::username($account['username'] ?? null);
        $hash = self::passwordHash($account['password_hash'] ?? null);
        $role = is_string($account['role'] ?? null) ? strtoupper(trim($account['role'])) : null;
        $active = self::boolean($account['active'] ?? null);
        $passwordChangedAt = self::nullableTimestamp($account['password_changed_at'] ?? null);
        $createdAt = self::timestamp($account['created_at'] ?? null);
        $updatedAt = self::timestamp($account['updated_at'] ?? null);
        $revision = self::integer($account['revision'] ?? null, 1);

        if ($id === null || $name === null || $username === null || $hash === null || $role !== 'ADMIN' || $active === null || $createdAt === null || $updatedAt === null || $revision === null) {
            throw new AdminAccountContractException('Admin account contract is invalid.');
        }

        return [
            'admin_id' => $id,
            'name' => $name,
            'username' => $username,
            'password_hash' => $hash,
            'role' => 'ADMIN',
            'active' => $active,
            'password_changed_at' => $passwordChangedAt,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
            'revision' => $revision,
        ];
    }

    /** @param list<mixed> $row */
    public static function sheetRow(array $row): array
    {
        $row = array_slice(array_pad($row, count(self::HEADERS), null), 0, count(self::HEADERS));
        return self::normalize(array_combine(self::HEADERS, $row) ?: []);
    }

    public static function normalizeUsername(mixed $username): ?string
    {
        if (! is_string($username)) return null;
        $username = strtolower(trim($username));
        return preg_match('/^[a-z0-9._-]{3,40}$/D', $username) === 1 ? $username : null;
    }

    /** @param array<string,mixed> $account @return array{admin_id:int,name:string,username:string,role:'ADMIN',active:bool,password_changed_at:string|null,created_at:string,updated_at:string,revision:int} */
    public static function publicView(array $account): array
    {
        $account = self::normalize($account);
        unset($account['password_hash']);
        return $account;
    }

    private static function name(mixed $value): ?string
    {
        if (! is_string($value)) return null;
        $value = trim($value);
        return $value !== '' && mb_strlen($value) <= 120 ? $value : null;
    }

    private static function username(mixed $value): ?string
    {
        return self::normalizeUsername($value);
    }

    private static function passwordHash(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') return null;
        $hash = trim($value);
        return (Hash::info($hash)['algoName'] ?? 'unknown') === 'unknown' ? null : $hash;
    }

    private static function integer(mixed $value, int $minimum): ?int
    {
        if (! is_int($value) && (! is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1)) return null;
        $value = (int) $value;
        return $value >= $minimum && $value <= 2147483647 ? $value : null;
    }

    private static function boolean(mixed $value): ?bool
    {
        return match ($value) {
            true, 1, '1', 'TRUE', 'true' => true,
            false, 0, '0', 'FALSE', 'false' => false,
            default => null,
        };
    }

    private static function nullableTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        return self::timestamp($value);
    }

    private static function timestamp(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $value) !== 1) return null;
        try {
            $time = new DateTimeImmutable($value);
            return $time->format('Y-m-d\\TH:i:s.v\\Z') === $value ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
