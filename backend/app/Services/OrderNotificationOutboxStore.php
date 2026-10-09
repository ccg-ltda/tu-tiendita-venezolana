<?php

namespace App\Services;

use App\Models\NotificationOutbox;
use Illuminate\Support\Facades\DB;

/** Durable SQL hand-off for order notifications. */
final class OrderNotificationOutboxStore
{
    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return NotificationOutbox::query()->where('status', 'PENDING')->where('available_at', '<=', now('UTC'))->orderBy('id')->get()
            ->map(fn (NotificationOutbox $entry): array => $this->payload($entry))->all();
    }

    /** @param array<string,mixed> $notification @return array{entry:array<string,mixed>,created:bool,idempotency_replayed:bool} */
    public function enqueue(array $notification): array
    {
        $entry = $this->normalize($notification);
        $model = NotificationOutbox::query()->firstOrCreate(['notification_key' => $entry['notification_key']], [
            'order_id' => $entry['order_id'], 'notification_type' => $entry['notification_type'],
            'recipient_kind' => $entry['recipient_kind'], 'status' => 'PENDING', 'attempts' => 0,
            'available_at' => now('UTC'), 'payload_json' => $entry,
        ]);

        return ['entry' => $this->payload($model), 'created' => $model->wasRecentlyCreated, 'idempotency_replayed' => ! $model->wasRecentlyCreated];
    }

    public function removeAfterSuccessfulDelivery(string $notificationKey): void
    {
        NotificationOutbox::query()->where('notification_key', $notificationKey)->delete();
    }

    /** @param array<string,mixed> $entry */
    public function markFailed(array $entry, string $error): void
    {
        $attempts = ((int) ($entry['attempts'] ?? 0)) + 1;
        NotificationOutbox::query()->where('notification_key', $entry['notification_key'] ?? null)->update([
            'attempts' => DB::raw('attempts + 1'), 'last_error' => substr(trim($error), 0, 500),
            'available_at' => now('UTC')->addMinutes($this->retryDelayMinutes($attempts)), 'updated_at' => now('UTC'),
        ]);
    }

    private function retryDelayMinutes(int $attempts): int
    {
        return match (true) {
            $attempts <= 1 => 1,
            $attempts === 2 => 2,
            $attempts === 3 => 5,
            default => 10,
        };
    }

    /** @return array<string,mixed> */
    private function payload(NotificationOutbox $entry): array
    {
        $payload = $entry->payload_json;
        if (! is_array($payload)) throw new \UnexpectedValueException('Invalid notification outbox payload.');
        return [...$payload, 'attempts' => $entry->attempts, 'last_error' => $entry->last_error];
    }

    /** @param array<string,mixed> $notification @return array<string,mixed> */
    private function normalize(array $notification): array
    {
        $type = $notification['notification_type'] ?? null;
        $recipient = $notification['recipient_kind'] ?? null;
        $allowed = ['PEDIDO_CREADO' => ['customer', 'admin'], 'EN_PREPARACION' => ['customer'], 'EN_CAMINO' => ['customer'], 'ENTREGADO' => ['customer']];
        if (! is_string($type) || ! isset($allowed[$type]) || ! in_array($recipient, $allowed[$type], true)) throw new \InvalidArgumentException('Invalid notification type.');
        $orderId = $notification['order_id'] ?? null;
        if (! is_int($orderId) || $orderId < 1) throw new \InvalidArgumentException('Invalid notification order.');
        $key = $type.':'.$orderId.':'.$recipient;
        if (($notification['notification_key'] ?? $key) !== $key) throw new \InvalidArgumentException('Invalid notification key.');
        return [...$notification, 'notification_key' => $key, 'notification_type' => $type, 'recipient_kind' => $recipient, 'order_id' => $orderId];
    }
}
