<?php

namespace App\Services;

use App\Admin\AdminAccountNormalizer;
use App\Repositories\MySqlAdminAuditRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Best-effort append-only auditing. It never changes a completed business response. */
final class AdminAuditService
{
    public function __construct(private readonly MySqlAdminAuditRepository $store) {}

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    public function record(Request $request, string $action, string $resourceType, int|string $resourceId, array $before = [], array $after = [], ?string $resourceLabel = null): void
    {
        $adminId = $request->session()->get('admin_id');
        $username = AdminAccountNormalizer::normalizeUsername($request->session()->get('admin_username'));
        if ((! is_int($adminId) && (! is_string($adminId) || ! ctype_digit($adminId))) || (int) $adminId < 1 || $username === null) {
            Log::warning('Administrative audit skipped: invalid authenticated actor.');
            return;
        }
        $requestId = $request->attributes->get('admin_audit_request_id');
        if (! is_string($requestId)) { $requestId = (string) Str::uuid(); $request->attributes->set('admin_audit_request_id', $requestId); }
        try {
            $this->store->append([
                'admin_id' => (int) $adminId, 'username' => $username, 'action' => $action, 'resource_type' => $resourceType,
                'resource_id' => (string) $resourceId, 'resource_label' => $this->resourceLabel($request, $resourceType, $resourceId, $before, $after, $resourceLabel), 'before_json' => $this->sanitize($before), 'after_json' => $this->sanitize($after),
                'created_at' => now('UTC'), 'request_id' => $requestId,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Administrative audit append failed after business operation.', ['action' => $action, 'resource_type' => $resourceType, 'resource_id' => (string) $resourceId, 'exception' => $exception::class]);
        }
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function sanitize(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (preg_match('/password|hash|token|cookie|secret|authorization|api[_-]?key|credential/i', (string) $key)) continue;
            $result[$key] = is_array($item) ? $this->sanitize($item) : $item;
        }
        return $result;
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    private function resourceLabel(Request $request, string $resourceType, int|string $resourceId, array $before, array $after, ?string $explicit): ?string
    {
        $label = is_string($explicit) && trim($explicit) !== '' ? trim($explicit) : match ($resourceType) {
            'ORDER' => $this->firstString($after, $before, ['reference']) ?? (is_string($resourceId) && ! ctype_digit($resourceId) ? $resourceId : null),
            'PRODUCT' => $this->firstString($after, $before, ['name']),
            'CATEGORY', 'SUBCATEGORY' => $this->firstString($after, $before, ['name']),
            'COUPON' => $this->firstString($after, $before, ['code']),
            'PROMOTION' => $this->firstString($after, $before, ['product_name', 'name']),
            'ADMIN' => $this->adminLabel($after, $before),
            'AUTH' => $this->sessionAdminLabel($request),
            default => null,
        };
        return $label === null ? null : mb_strimwidth($label, 0, 255, '');
    }

    /** @param array<string,mixed> ...$snapshots @param list<string> $keys */
    private function firstString(array $after, array $before, array $keys): ?string
    {
        foreach ([$after, $before] as $snapshot) foreach ($keys as $key) if (is_string($snapshot[$key] ?? null) && trim($snapshot[$key]) !== '') return trim($snapshot[$key]);
        return null;
    }

    /** @param array<string,mixed> $after @param array<string,mixed> $before */
    private function adminLabel(array $after, array $before): ?string
    {
        foreach ([$after, $before] as $snapshot) {
            $name = is_string($snapshot['name'] ?? null) ? trim($snapshot['name']) : '';
            $username = is_string($snapshot['username'] ?? null) ? trim($snapshot['username']) : '';
            if ($name !== '') return $username === '' ? $name : "{$name} ({$username})";
        }
        return null;
    }

    private function sessionAdminLabel(Request $request): ?string
    {
        $name = $request->session()->get('admin_name'); $username = $request->session()->get('admin_username');
        if (! is_string($name) || trim($name) === '') return is_string($username) && trim($username) !== '' ? trim($username) : null;
        return is_string($username) && trim($username) !== '' ? trim($name)." (".trim($username).")" : trim($name);
    }

}
