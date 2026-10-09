<?php

namespace App\Repositories;

use App\Models\AdminAuditLog;
use Illuminate\Database\Eloquent\Builder;

final class MySqlAdminAuditRepository
{
    /** @param array<string,mixed> $record */
    public function append(array $record): void
    {
        AdminAuditLog::query()->create($record);
    }

    /** @param array{admin_id?:int,resource_type?:string,action?:string,date_from?:string,date_to?:string,include_auth:bool} $filters */
    public function page(int $limit, ?int $beforeId, array $filters): array
    {
        $query = AdminAuditLog::query()->orderByDesc('id');
        if ($beforeId !== null) $query->where('id', '<', $beforeId);
        if (! $filters['include_auth']) $query->whereNotIn('action', ['LOGIN', 'LOGOUT']);
        foreach (['admin_id', 'resource_type', 'action'] as $field) if (isset($filters[$field])) $query->where($field, $filters[$field]);
        if (isset($filters['date_from'])) $query->where('created_at', '>=', $filters['date_from'].' 00:00:00');
        if (isset($filters['date_to'])) $query->where('created_at', '<=', $filters['date_to'].' 23:59:59.999');
        $records = $query->limit($limit + 1)->get();
        $hasMore = $records->count() > $limit;
        $items = $records->take($limit)->map(fn (AdminAuditLog $record): array => $this->record($record))->all();
        $last = $items[array_key_last($items)] ?? null;
        return ['items' => $items, 'has_more' => $hasMore, 'next_before_id' => $hasMore ? $last['audit_id'] : null];
    }

    /** @return array<string,mixed> */
    private function record(AdminAuditLog $record): array
    {
        return ['audit_id' => $record->id, 'admin_id' => $record->admin_id, 'username' => $record->username, 'action' => $record->action, 'resource_type' => $record->resource_type, 'resource_id' => $record->resource_id, 'resource_label' => $record->resource_label, 'before' => $this->sanitize(is_array($record->before_json) ? $record->before_json : []), 'after' => $this->sanitize(is_array($record->after_json) ? $record->after_json : []), 'created_at' => $record->created_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'), 'request_id' => $record->request_id];
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
}
