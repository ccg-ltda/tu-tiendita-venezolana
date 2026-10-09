<?php

namespace App\Services;

use App\Models\PaymentEventOutbox;
use Illuminate\Support\Facades\DB;

/** SQL-backed retry outbox for Wompi webhook events. */
final class WompiPaymentEventOutboxStore
{
    /** @return list<array{transaction:array<string,mixed>,attempts:int,last_attempt_at:string|null,last_error:string|null}> */
    public function all(): array
    {
        return PaymentEventOutbox::query()->where('status', 'PENDING')->where('available_at', '<=', now('UTC'))->orderBy('id')->get()
            ->map(fn (PaymentEventOutbox $event): array => $this->payload($event))->all();
    }

    /** @param array<string,mixed> $transaction */
    public function enqueue(array $transaction): void
    {
        $this->assertTransaction($transaction);
        PaymentEventOutbox::query()->updateOrCreate(['wompi_transaction_id' => $transaction['id']], [
            'reference' => $transaction['reference'], 'event_status' => $transaction['status'],
            'payment_method' => $transaction['payment_method'], 'amount_in_cents' => $transaction['amount_in_cents'],
            'currency' => $transaction['currency'], 'event_occurred_at' => $transaction['event_occurred_at'],
            'status' => 'PENDING', 'available_at' => now('UTC'), 'last_error' => null,
        ]);
    }

    public function remove(string $transactionId): void
    {
        PaymentEventOutbox::query()->where('wompi_transaction_id', $transactionId)->delete();
    }

    /** @param array{transaction:array{id:string},attempts:int,last_attempt_at:string|null,last_error:string|null} $event */
    public function markFailed(array $event): void
    {
        $attempts = ((int) ($event['attempts'] ?? 0)) + 1;
        PaymentEventOutbox::query()->where('wompi_transaction_id', $event['transaction']['id'])->update([
            'attempts' => DB::raw('attempts + 1'), 'last_error' => 'sql_checkout_unavailable',
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

    /** @return array{transaction:array<string,mixed>,attempts:int,last_attempt_at:string|null,last_error:string|null} */
    private function payload(PaymentEventOutbox $event): array
    {
        return ['transaction' => [
            'id' => $event->wompi_transaction_id, 'reference' => $event->reference, 'status' => $event->event_status,
            'payment_method' => $event->payment_method, 'amount_in_cents' => $event->amount_in_cents,
            'currency' => $event->currency, 'event_occurred_at' => $event->event_occurred_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
        ], 'attempts' => $event->attempts, 'last_attempt_at' => $event->updated_at?->utc()->format('Y-m-d\\TH:i:s.v\\Z'), 'last_error' => $event->last_error];
    }

    /** @param array<string,mixed> $transaction */
    private function assertTransaction(array $transaction): void
    {
        if (! is_string($transaction['id'] ?? null) || trim($transaction['id']) === '' || ! is_string($transaction['reference'] ?? null)
            || ! in_array($transaction['status'] ?? null, ['PENDING', 'APPROVED', 'DECLINED', 'VOIDED', 'ERROR'], true)
            || ! is_string($transaction['payment_method'] ?? null) || ! is_int($transaction['amount_in_cents'] ?? null)
            || $transaction['amount_in_cents'] < 1 || ($transaction['currency'] ?? null) !== 'COP' || ! is_string($transaction['event_occurred_at'] ?? null)) {
            throw new \InvalidArgumentException('Invalid Wompi payment event.');
        }
    }
}
