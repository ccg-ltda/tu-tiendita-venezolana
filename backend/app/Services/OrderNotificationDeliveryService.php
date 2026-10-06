<?php

namespace App\Services;

use App\Mail\OrderCreatedAdminMail;
use App\Mail\OrderCreatedCustomerMail;
use App\Mail\OrderDeliveredCustomerMail;
use App\Mail\OrderPreparingCustomerMail;
use App\Mail\OrderShippedCustomerMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Delivers an already-persisted notification; never participates in checkout. */
final class OrderNotificationDeliveryService
{
    public function __construct(private readonly OrderNotificationOutboxStore $outbox) {}

    /** @param array<string,mixed> $entry */
    public function deliver(array $entry): bool
    {
        try {
            [$recipient, $mail] = $this->mailFor($entry);
            Mail::to($recipient)->send($mail);
            $this->outbox->removeAfterSuccessfulDelivery($entry['notification_key']);

            return true;
        } catch (\Throwable $exception) {
            $this->recordFailure($entry, $exception);

            return false;
        }
    }

    /** @param array<string,mixed> $entry @return array{0:string|list<string>,1:OrderCreatedCustomerMail|OrderCreatedAdminMail|OrderPreparingCustomerMail|OrderShippedCustomerMail|OrderDeliveredCustomerMail} */
    private function mailFor(array $entry): array
    {
        $type = $entry['notification_type'] ?? null;
        $kind = $entry['recipient_kind'] ?? null;
        if ($type === 'PEDIDO_CREADO' && $kind === 'customer') {
            $recipient = $entry['customer_email'] ?? null;
            if (! $this->validEmail($recipient)) throw new \InvalidArgumentException('Invalid customer notification email.');

            return [$recipient, new OrderCreatedCustomerMail($entry)];
        }
        if ($type === 'PEDIDO_CREADO' && $kind === 'admin') {
            $recipients = $this->adminRecipients(config('services.order_notifications.admin_email'));
            if ($recipients === []) throw new \InvalidArgumentException('Order notification admin email is unavailable.');

            return [$recipients, new OrderCreatedAdminMail($entry)];
        }
        if (in_array($type, ['EN_PREPARACION', 'EN_CAMINO', 'ENTREGADO'], true)) {
            if ($kind !== 'customer') throw new \InvalidArgumentException('Operational order notifications require a customer recipient.');
            $recipient = $entry['customer_email'] ?? null;
            if (! $this->validEmail($recipient)) throw new \InvalidArgumentException('Invalid customer notification email.');
            return [$recipient, match ($type) {
                'EN_PREPARACION' => new OrderPreparingCustomerMail($entry),
                'EN_CAMINO' => new OrderShippedCustomerMail($entry),
                'ENTREGADO' => new OrderDeliveredCustomerMail($entry),
            }];
        }

        throw new \InvalidArgumentException('Invalid order notification type or recipient kind.');
    }

    /** @param array<string,mixed> $entry */
    private function recordFailure(array $entry, \Throwable $exception): void
    {
        $error = trim($exception->getMessage());
        $error = $error !== '' ? substr($error, 0, 500) : 'order_notification_delivery_failed';
        try {
            $this->outbox->markFailed($entry, $error);
        } catch (\Throwable $outboxException) {
            Log::error('Order notification delivery failure could not be persisted.', [
                'notification_key' => $entry['notification_key'] ?? null,
                'error' => $outboxException->getMessage(),
            ]);
        }
        Log::warning('Order notification delivery failed.', [
            'notification_key' => $entry['notification_key'] ?? null,
            'recipient_kind' => $entry['recipient_kind'] ?? null,
            'error' => $error,
        ]);
    }

    private function validEmail(mixed $email): bool
    {
        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** @return list<string> */
    private function adminRecipients(mixed $configuredRecipients): array
    {
        if (!is_string($configuredRecipients)) return [];

        $recipients = [];
        $seen = [];
        foreach (explode(',', $configuredRecipients) as $recipient) {
            $recipient = trim($recipient);
            if ($recipient === '' || !$this->validEmail($recipient)) continue;

            $key = strtolower($recipient);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $recipients[] = $recipient;
        }

        return $recipients;
    }
}
