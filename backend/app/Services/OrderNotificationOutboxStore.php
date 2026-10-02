<?php

namespace App\Services;

/**
 * Durable, idempotent hand-off for order notifications. This store never sends
 * mail; entries remain PENDING until a future delivery worker removes them.
 */
final class OrderNotificationOutboxStore
{
    private const SCHEMA = 1;
    private const TYPES = [
        'PEDIDO_CREADO' => ['customer', 'admin'],
        'EN_PREPARACION' => ['customer'],
        'EN_CAMINO' => ['customer'],
        'ENTREGADO' => ['customer'],
    ];

    public function __construct(private readonly ?string $path = null) {}

    /** @return list<array<string,mixed>> */
    public function all(): array { return $this->read(); }

    /** @param array<string,mixed> $notification @return array{entry:array<string,mixed>,created:bool,idempotency_replayed:bool} */
    public function enqueue(array $notification): array
    {
        $entry = $this->normalize($notification);
        $entries = $this->read();
        foreach ($entries as $stored) {
            if ($stored['notification_key'] === $entry['notification_key']) {
                return ['entry' => $stored, 'created' => false, 'idempotency_replayed' => true];
            }
        }

        $entries[] = $entry;
        $this->write($entries);

        return ['entry' => $entry, 'created' => true, 'idempotency_replayed' => false];
    }

    /** Call only after a future delivery worker has received a successful send result. */
    public function removeAfterSuccessfulDelivery(string $notificationKey): void
    {
        $entries = $this->read();
        $remaining = array_values(array_filter($entries, static fn (array $entry): bool => $entry['notification_key'] !== $notificationKey));
        if (count($remaining) !== count($entries)) $this->write($remaining);
    }

    /** @param array<string,mixed> $entry */
    public function markFailed(array $entry, string $error): void
    {
        if (!is_string($error) || trim($error) === '') throw new \InvalidArgumentException('Invalid notification error.');
        $entries = $this->read();
        foreach ($entries as $index => $stored) {
            if ($stored['notification_key'] !== ($entry['notification_key'] ?? null)) continue;
            $stored['attempts']++;
            $stored['last_error'] = trim($error);
            $stored['updated_at'] = now('UTC')->format('Y-m-d\\TH:i:s.v\\Z');
            $entries[$index] = $stored;
            $this->write($entries);
            return;
        }
    }

    /** @param array<string,mixed> $notification @return array<string,mixed> */
    private function normalize(array $notification): array
    {
        $type = $notification['notification_type'] ?? null;
        if (!is_string($type) || !isset(self::TYPES[$type])) throw new \InvalidArgumentException('Invalid notification type.');
        $orderId = $this->integer($notification['order_id'] ?? null, 1);
        $recipient = $notification['recipient_kind'] ?? null;
        if (!in_array($recipient, self::TYPES[$type], true)) throw new \InvalidArgumentException('Invalid notification recipient.');
        $key = $type.':'.$orderId.':'.$recipient;
        if (($notification['notification_key'] ?? $key) !== $key) throw new \InvalidArgumentException('Invalid notification key.');
        $items = $notification['items'] ?? null;
        if (!is_array($items) || $items === []) throw new \InvalidArgumentException('Invalid notification items.');
        $normalizedItems = array_map(function (mixed $item): array {
            if (!is_array($item)) throw new \InvalidArgumentException('Invalid notification item.');
            return [
                'product_id' => $this->integer($item['product_id'] ?? null, 1),
                'product_name' => $this->text($item['product_name'] ?? null, 1, 500),
                'unit_price_cop' => $this->integer($item['unit_price_cop'] ?? null, 0),
                'quantity' => $this->integer($item['quantity'] ?? null, 1),
            ];
        }, $items);
        $now = now('UTC')->format('Y-m-d\\TH:i:s.v\\Z');

        return [
            'notification_key' => $key, 'notification_type' => $type, 'recipient_kind' => $recipient,
            'order_id' => $orderId, 'reference' => $this->text($notification['reference'] ?? null, 1, 120),
            'customer_name' => $this->text($notification['customer_name'] ?? null, 1, 120),
            'customer_email' => $this->text($notification['customer_email'] ?? null, 3, 254),
            'customer_phone' => $this->text($notification['customer_phone'] ?? null, 1, 50),
            'address' => $this->text($notification['address'] ?? null, 1, 300),
            'extra' => $this->nullableText($notification['extra'] ?? null, 300),
            'city' => $this->text($notification['city'] ?? null, 1, 100),
            'total_cop' => $this->integer($notification['total_cop'] ?? null, 0),
            'status' => $this->text($notification['status'] ?? null, 1, 100),
            'payment_status' => $this->text($notification['payment_status'] ?? null, 1, 100),
            'reservation_status' => $this->text($notification['reservation_status'] ?? null, 1, 100),
            'items' => $normalizedItems, 'status_internal' => 'PENDING', 'attempts' => 0, 'last_error' => null,
            'created_at' => $now, 'updated_at' => $now,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function read(): array
    {
        $path = $this->path();
        if (!is_file($path) || is_link($path)) return [];
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data) || ($data['schema_version'] ?? null) !== self::SCHEMA || !is_array($data['entries'] ?? null)) return [];
        $entries = [];
        foreach ($data['entries'] as $entry) {
            try { $entries[] = $this->normalizePersisted($entry); } catch (\Throwable) { continue; }
        }
        return $entries;
    }

    /** @param mixed $entry @return array<string,mixed> */
    private function normalizePersisted(mixed $entry): array
    {
        if (!is_array($entry)) throw new \InvalidArgumentException;
        $normalized = $this->normalize($entry);
        if (($entry['status_internal'] ?? null) !== 'PENDING' || !is_int($entry['attempts'] ?? null) || $entry['attempts'] < 0 || ($entry['last_error'] ?? null) !== null && !is_string($entry['last_error'])) throw new \InvalidArgumentException;
        foreach (['created_at', 'updated_at'] as $field) if (!$this->timestamp($entry[$field] ?? null)) throw new \InvalidArgumentException;
        $normalized['attempts'] = $entry['attempts'];
        $normalized['last_error'] = $entry['last_error'];
        $normalized['created_at'] = $entry['created_at'];
        $normalized['updated_at'] = $entry['updated_at'];
        return $normalized;
    }

    /** @param list<array<string,mixed>> $entries */
    private function write(array $entries): void
    {
        $path = $this->path(); $directory = dirname($path);
        if (is_link(storage_path('app/private')) || is_link($directory) || is_link($path)) throw new \RuntimeException('Order notification outbox directory unsafe.');
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) throw new \RuntimeException('Order notification outbox unavailable.');
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        try {
            $json = json_encode(['schema_version' => self::SCHEMA, 'entries' => array_values($entries)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (@file_put_contents($temporary, $json, LOCK_EX) === false) throw new \RuntimeException('Order notification outbox write failed.');
            if (!@rename($temporary, $path)) {
                $backup = $path.'.backup.'.bin2hex(random_bytes(6));
                if (is_file($path) && !@rename($path, $backup)) throw new \RuntimeException('Order notification outbox replacement failed.');
                try {
                    if (!@rename($temporary, $path)) throw new \RuntimeException('Order notification outbox replacement failed.');
                    @unlink($backup);
                } catch (\Throwable $exception) {
                    if (!is_file($path) && is_file($backup)) @rename($backup, $path);
                    throw $exception;
                }
            }
            @chmod($path, 0640);
        } finally { if (is_file($temporary)) @unlink($temporary); }
    }

    private function text(mixed $value, int $min, int $max): string { if (!is_string($value) || $value !== trim($value) || strlen($value) < $min || strlen($value) > $max) throw new \InvalidArgumentException('Invalid notification text.'); return $value; }
    private function nullableText(mixed $value, int $max): ?string { return $value === null || $value === '' ? null : $this->text($value, 1, $max); }
    private function integer(mixed $value, int $min): int { if (!is_int($value) || $value < $min || $value > 2147483647) throw new \InvalidArgumentException('Invalid notification integer.'); return $value; }
    private function timestamp(mixed $value): bool { return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $value) === 1; }
    private function path(): string { return $this->path ?? storage_path('app/private/order-notifications/outbox.json'); }
}
