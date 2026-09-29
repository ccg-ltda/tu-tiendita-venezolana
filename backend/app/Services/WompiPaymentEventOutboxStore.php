<?php

namespace App\Services;

final class WompiPaymentEventOutboxStore
{
    private const SCHEMA = 1;

    public function __construct(private readonly ?string $path = null) {}

    /** @return list<array{transaction: array{id:string,reference:string,status:string,payment_method:string,amount_in_cents:int,currency:string,event_occurred_at:string},attempts:int,last_attempt_at:string|null,last_error:string|null}> */
    public function all(): array
    {
        return $this->read();
    }

    /** @param array{id:string,reference:string,status:string,payment_method:string,amount_in_cents:int,currency:string,event_occurred_at:string} $transaction */
    public function enqueue(array $transaction): void
    {
        $this->assertTransaction($transaction);
        $events = $this->read();
        foreach ($events as $index => $event) {
            if ($event['transaction']['id'] !== $transaction['id']) {
                continue;
            }

            if ($event['transaction']['event_occurred_at'] > $transaction['event_occurred_at']) {
                return;
            }

            $events[$index]['transaction'] = $transaction;
            $events[$index]['last_error'] = null;
            $this->write($events);

            return;
        }

        $events[] = ['transaction' => $transaction, 'attempts' => 0, 'last_attempt_at' => null, 'last_error' => null];
        $this->write($events);
    }

    public function remove(string $transactionId): void
    {
        $events = $this->read();
        $remaining = array_values(array_filter($events, static fn (array $event): bool => $event['transaction']['id'] !== $transactionId));
        if (count($remaining) !== count($events)) {
            $this->write($remaining);
        }
    }

    /** @param array{transaction: array{id:string,reference:string,status:string,payment_method:string,amount_in_cents:int,currency:string,event_occurred_at:string},attempts:int,last_attempt_at:string|null,last_error:string|null} $event */
    public function markFailed(array $event): void
    {
        $events = $this->read();
        foreach ($events as $index => $stored) {
            if ($stored['transaction']['id'] === $event['transaction']['id']) {
                $event['attempts'] = $stored['attempts'] + 1;
                $event['last_attempt_at'] = now('UTC')->format('Y-m-d\TH:i:s.v\Z');
                $event['last_error'] = 'apps_script_unavailable';
                $events[$index] = $event;
                $this->write($events);

                return;
            }
        }
    }

    /** @return list<array{transaction: array{id:string,reference:string,status:string,payment_method:string,amount_in_cents:int,currency:string,event_occurred_at:string},attempts:int,last_attempt_at:string|null,last_error:string|null}> */
    private function read(): array
    {
        $path = $this->path();
        if (! is_file($path) || is_link($path)) {
            return [];
        }

        $data = json_decode((string) @file_get_contents($path), true);
        if (! is_array($data) || ($data['schema_version'] ?? null) !== self::SCHEMA || ! is_array($data['events'] ?? null)) {
            return [];
        }

        $events = [];
        foreach ($data['events'] as $event) {
            if (! is_array($event) || ! is_array($event['transaction'] ?? null)) {
                continue;
            }
            try {
                $this->assertTransaction($event['transaction']);
            } catch (\Throwable) {
                continue;
            }
            $events[] = [
                'transaction' => $event['transaction'],
                'attempts' => is_int($event['attempts'] ?? null) && $event['attempts'] >= 0 ? $event['attempts'] : 0,
                'last_attempt_at' => is_string($event['last_attempt_at'] ?? null) ? $event['last_attempt_at'] : null,
                'last_error' => is_string($event['last_error'] ?? null) ? $event['last_error'] : null,
            ];
        }

        return $events;
    }

    /** @param list<array{transaction: array{id:string,reference:string,status:string,payment_method:string,amount_in_cents:int,currency:string,event_occurred_at:string},attempts:int,last_attempt_at:string|null,last_error:string|null}> $events */
    private function write(array $events): void
    {
        $path = $this->path();
        $directory = dirname($path);
        if (is_link(storage_path('app/private')) || is_link($directory)) {
            throw new \RuntimeException('Wompi payment-event outbox directory unsafe.');
        }
        if (! is_dir($directory) && ! @mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Wompi payment-event outbox unavailable.');
        }

        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        try {
            $json = json_encode(['schema_version' => self::SCHEMA, 'events' => array_values($events)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (@file_put_contents($temporary, $json, LOCK_EX) === false) {
                throw new \RuntimeException('Wompi payment-event outbox write failed.');
            }
            if (! @rename($temporary, $path)) {
                $backup = $path.'.backup.'.bin2hex(random_bytes(6));
                if (is_file($path) && ! @rename($path, $backup)) {
                    throw new \RuntimeException('Wompi payment-event outbox replacement failed.');
                }
                try {
                    if (! @rename($temporary, $path)) {
                        throw new \RuntimeException('Wompi payment-event outbox replacement failed.');
                    }
                    @unlink($backup);
                } catch (\Throwable $exception) {
                    if (! is_file($path) && is_file($backup)) {
                        @rename($backup, $path);
                    }
                    throw $exception;
                }
            }
            @chmod($path, 0640);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /** @param array<string,mixed> $transaction */
    private function assertTransaction(array $transaction): void
    {
        if (! is_string($transaction['id'] ?? null) || trim($transaction['id']) === ''
            || ! is_string($transaction['reference'] ?? null) || trim($transaction['reference']) === ''
            || ! in_array($transaction['status'] ?? null, ['PENDING', 'APPROVED', 'DECLINED', 'VOIDED', 'ERROR'], true)
            || ! is_string($transaction['payment_method'] ?? null) || trim($transaction['payment_method']) === ''
            || ! is_int($transaction['amount_in_cents'] ?? null) || $transaction['amount_in_cents'] < 1
            || ($transaction['currency'] ?? null) !== 'COP'
            || ! is_string($transaction['event_occurred_at'] ?? null) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $transaction['event_occurred_at']) !== 1) {
            throw new \InvalidArgumentException('Invalid Wompi payment event.');
        }
    }

    private function path(): string
    {
        return $this->path ?? storage_path('app/private/wompi/payment-events-outbox.json');
    }
}
