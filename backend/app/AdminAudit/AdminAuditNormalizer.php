<?php

namespace App\AdminAudit;

use DateTimeImmutable;

final class AdminAuditNormalizer
{
    public const HEADERS = ['audit_id','admin_id','username','action','resource_type','resource_id','before_json','after_json','created_at','request_id'];
    private const ACTIONS = ['CREATE','UPDATE','STATUS_CHANGE','ACTIVATE','DEACTIVATE','LOGIN','LOGOUT'];
    private const RESOURCES = ['PRODUCT','PROMOTION','COUPON','ORDER','ADMIN','AUTH'];

    /** @param array<string,mixed> $record @return array<string,int|string> */
    public static function normalize(array $record): array
    {
        $auditId = self::positiveInt($record['audit_id'] ?? null);
        $adminId = self::positiveInt($record['admin_id'] ?? null);
        $username = is_string($record['username'] ?? null) ? strtolower(trim($record['username'])) : null;
        $action = is_string($record['action'] ?? null) ? strtoupper(trim($record['action'])) : null;
        $resourceType = is_string($record['resource_type'] ?? null) ? strtoupper(trim($record['resource_type'])) : null;
        $resourceId = is_int($record['resource_id'] ?? null) || is_string($record['resource_id'] ?? null) ? trim((string) $record['resource_id']) : null;
        $before = self::json($record['before_json'] ?? null);
        $after = self::json($record['after_json'] ?? null);
        $createdAt = self::timestamp($record['created_at'] ?? null);
        $requestId = is_string($record['request_id'] ?? null) ? strtolower(trim($record['request_id'])) : null;

        if ($auditId === null || $adminId === null || $username === null || preg_match('/^[a-z0-9._-]{3,40}$/D', $username) !== 1 || ! in_array($action, self::ACTIONS, true) || ! in_array($resourceType, self::RESOURCES, true) || $resourceId === null || $resourceId === '' || strlen($resourceId) > 128 || $before === null || $after === null || $createdAt === null || $requestId === null || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $requestId) !== 1) {
            throw new AdminAuditContractException('Invalid audit record.');
        }

        return [
            'audit_id' => $auditId, 'admin_id' => $adminId, 'username' => $username, 'action' => $action, 'before_json' => $before, 'after_json' => $after,
            'resource_type' => $resourceType, 'resource_id' => $resourceId, 'created_at' => $createdAt, 'request_id' => $requestId,
        ];
    }

    /** @param list<mixed> $row @return array<string,int|string> */
    public static function sheetRow(array $row): array
    {
        $row = array_slice(array_pad($row, count(self::HEADERS), null), 0, count(self::HEADERS));
        return self::normalize(array_combine(self::HEADERS, $row) ?: []);
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (! is_int($value) && (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1)) return null;
        $value = (int) $value;
        return $value > 0 && $value <= 2147483647 ? $value : null;
    }

    private static function json(mixed $value): ?string
    {
        if (! is_string($value)) return null;
        try { json_decode($value, true, 512, JSON_THROW_ON_ERROR); return $value; } catch (\Throwable) { return null; }
    }

    private static function timestamp(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $value) !== 1) return null;
        try { return (new DateTimeImmutable($value))->format('Y-m-d\\TH:i:s.v\\Z') === $value ? $value : null; } catch (\Throwable) { return null; }
    }
}
