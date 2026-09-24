<?php

namespace App\Services;

final class ProductOutboxStore
{
    private const SCHEMA = 1;

    public function __construct(private readonly ?string $path = null) {}

    public function all(): array
    {
        $operations = $this->readRaw();
        $compact = $this->compactOperations($operations);
        if ($compact !== $operations) $this->write($compact);
        return $compact;
    }

    public function enqueue(array $operation): void
    {
        $operations = $this->compactOperations($this->readRaw());
        $productId = (int) ($operation['product_id'] ?? 0);
        $same = array_values(array_filter($operations, fn (array $item): bool => (int) ($item['product_id'] ?? 0) === $productId));
        $createIndex = null;
        foreach ($operations as $index => $item) {
            if ((int) ($item['product_id'] ?? 0) === $productId && ($item['type'] ?? null) === 'CREATE') {
                $createIndex = $index;
                break;
            }
        }

        if ($createIndex !== null) {
            $base = $operations[$createIndex];
            $merged = $this->mergeDesiredState($base, $operation);
            $merged['type'] = 'CREATE';
            $merged['expected_revision'] = null;
            $operations = array_values(array_filter($operations, fn (array $item, int $index): bool => $index === $createIndex || (int) ($item['product_id'] ?? 0) !== $productId, ARRAY_FILTER_USE_BOTH));
            $operations[$createIndex] = $merged;
            ksort($operations);
            $this->write(array_values($operations));
            return;
        }

        $updateIndexes = [];
        foreach ($operations as $index => $item) {
            if ((int) ($item['product_id'] ?? 0) === $productId && in_array($item['type'] ?? null, ['UPDATE', 'SET_ACTIVE'], true)) $updateIndexes[] = $index;
        }
        if ($updateIndexes !== []) {
            $first = $updateIndexes[0];
            $merged = $this->mergeDesiredState($operations[$first], $operation);
            $operations = array_values(array_filter($operations, fn (array $item, int $index): bool => !in_array($index, $updateIndexes, true), ARRAY_FILTER_USE_BOTH));
            array_splice($operations, min($first, count($operations)), 0, [$merged]);
            $this->write($operations);
            return;
        }

        $operations[] = $operation;
        $this->write($operations);
    }

    public function remove(string $id): void
    { $this->write(array_values(array_filter($this->readRaw(), fn (array $op): bool => ($op['operation_id'] ?? null) !== $id))); }

    public function update(array $operation): void
    {
        $id = $operation['operation_id'] ?? null;
        $operations = $this->readRaw();
        foreach ($operations as $index => $item) if (($item['operation_id'] ?? null) === $id) $operations[$index] = $operation;
        $this->write($operations);
    }

    private function mergeDesiredState(array $base, array $latest): array
    {
        $product = $latest['product'] ?? $latest['payload'] ?? null;
        if (!is_array($product)) $product = $base['product'] ?? $base['payload'] ?? [];
        if (($product['created_at'] ?? null) === null && ($base['product']['created_at'] ?? null) !== null) $product['created_at'] = $base['product']['created_at'];
        if (($product['created_at'] ?? null) === null && ($base['payload']['created_at'] ?? null) !== null) $product['created_at'] = $base['payload']['created_at'];
        $base['product'] = $product;
        $base['payload'] = $product;
        $base['target_revision'] = $latest['target_revision'] ?? $product['revision'] ?? $base['target_revision'] ?? null;
        $base['last_error'] = $latest['last_error'] ?? $base['last_error'] ?? null;
        return $base;
    }

    private function compactOperations(array $operations): array
    {
        $result = [];
        $positions = [];
        foreach ($operations as $operation) {
            $id = (int) ($operation['product_id'] ?? 0);
            if ($id < 1) continue;
            $index = $positions[$id] ?? null;
            if ($index === null) { $positions[$id] = count($result); $result[] = $operation; continue; }
            $existing = $result[$index];
            $existingType = $existing['type'] ?? null;
            $incomingType = $operation['type'] ?? null;
            if ($existingType === 'CREATE' || in_array($existingType, ['UPDATE', 'SET_ACTIVE'], true) && in_array($incomingType, ['UPDATE', 'SET_ACTIVE'], true)) {
                $merged = $this->mergeDesiredState($existing, $operation);
                if ($existingType === 'CREATE') { $merged['type'] = 'CREATE'; $merged['expected_revision'] = null; }
                $result[$index] = $merged;
            } else { $positions[$id] = count($result); $result[] = $operation; }
        }
        return $result;
    }

    private function readRaw(): array
    {
        $path = $this->path();
        if (!is_file($path) || is_link($path)) return [];
        $data = json_decode((string) @file_get_contents($path), true);
        return is_array($data) && ($data['schema_version'] ?? null) === self::SCHEMA && is_array($data['operations'] ?? null) ? $data['operations'] : [];
    }

    private function write(array $operations): void
    {
        $dir = dirname($this->path());
        if (is_link(storage_path('app/private')) || is_link($dir)) throw new \RuntimeException('Product outbox directory unsafe.');
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) throw new \RuntimeException('Product outbox unavailable.');
        $tmp = $this->path().'.'.bin2hex(random_bytes(8)).'.tmp';
        $json = json_encode(['schema_version'=>self::SCHEMA,'operations'=>array_values($operations)], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        try {
            if (@file_put_contents($tmp, $json, LOCK_EX) === false) throw new \RuntimeException('Product outbox write failed.');
            if (!@rename($tmp, $this->path())) {
                $backup = $this->path().'.backup.'.bin2hex(random_bytes(6));
                if (is_file($this->path()) && !@rename($this->path(), $backup)) throw new \RuntimeException('Product outbox replacement failed.');
                try { if (!@rename($tmp, $this->path())) throw new \RuntimeException('Product outbox replacement failed.'); @unlink($backup); }
                catch (\Throwable $e) { if (!is_file($this->path()) && is_file($backup)) @rename($backup, $this->path()); throw $e; }
            }
            @chmod($this->path(), 0640);
        } finally { if (is_file($tmp)) @unlink($tmp); }
    }

    private function path(): string { return $this->path ?? storage_path('app/private/catalog/products-outbox.json'); }
}
