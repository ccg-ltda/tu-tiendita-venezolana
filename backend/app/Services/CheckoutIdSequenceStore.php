<?php

namespace App\Services;

use App\Exceptions\CheckoutConsistencyException;
use Illuminate\Support\Facades\Log;

final class CheckoutIdSequenceStore
{
    private const FIELDS = ['next_order_id','next_order_item_id','next_payment_attempt_id'];

    public function __construct(private readonly ?string $path = null) {}

    /** @return array{next_order_id:int,next_order_item_id:int,next_payment_attempt_id:int} */
    public function initializeFromMaxima(int $maxOrderId, int $maxOrderItemId, int $maxPaymentAttemptId): array
    {
        return $this->mutate(function (array $sequences) use ($maxOrderId, $maxOrderItemId, $maxPaymentAttemptId): array {
            foreach ([
                'next_order_id' => $maxOrderId,
                'next_order_item_id' => $maxOrderItemId,
                'next_payment_attempt_id' => $maxPaymentAttemptId,
            ] as $field => $maximum) {
                if ($maximum < 0 || $maximum >= 2147483647) {
                    throw new CheckoutConsistencyException;
                }

                $sequences[$field] = max($sequences[$field], $maximum + 1);
            }

            return [$sequences, $sequences];
        });
    }

    public function reserveOrderId(int $maxOrderId = 0): int
    {
        return $this->reserve('next_order_id', 1, $maxOrderId);
    }

    /** @return int First reserved ID. */
    public function reserveOrderItemIds(int $count, int $maxOrderItemId = 0): int
    {
        if ($count < 1 || $count > 50) {
            throw new CheckoutConsistencyException;
        }

        return $this->reserve('next_order_item_id', $count, $maxOrderItemId);
    }

    public function reservePaymentAttemptId(int $maxPaymentAttemptId = 0): int
    {
        return $this->reserve('next_payment_attempt_id', 1, $maxPaymentAttemptId);
    }

    private function reserve(string $field, int $count, int $maximum): int
    {
        return $this->mutate(function (array $sequences) use ($field, $count, $maximum): array {
            if ($maximum < 0 || $maximum >= 2147483647) {
                throw new CheckoutConsistencyException;
            }

            $next = max($sequences[$field], $maximum + 1);

            if ($next > 2147483647 || $count > 2147483648 - $next) {
                throw new CheckoutConsistencyException;
            }

            $sequences[$field] = $next + $count;

            return [$sequences, $next];
        });
    }

    private function mutate(callable $callback): mixed
    {
        $path = $this->filePath();
        $lockPath = $path . '.lock';

        $handle = @fopen($lockPath, 'c');

        if ($handle === false || ! @flock($handle, LOCK_EX)) {
            throw new CheckoutConsistencyException;
        }

        try {
            $sequences = $this->read($path);

            [$next, $result] = $callback($sequences);

            $this->validate($next);
            $this->write($path, $next);

            return $result;
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /** @return array{next_order_id:int,next_order_item_id:int,next_payment_attempt_id:int} */
    private function read(string $path): array
    {
        if (! is_file($path)) {
            return array_fill_keys(self::FIELDS, 1);
        }

        $raw = @file_get_contents($path);

        if (! is_string($raw)) {
            throw new CheckoutConsistencyException;
        }

        try {
            $sequences = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new CheckoutConsistencyException;
        }

        if (! is_array($sequences)) {
            throw new CheckoutConsistencyException;
        }

        $this->validate($sequences);

        return $sequences;
    }

    /** @param array{next_order_id:int,next_order_item_id:int,next_payment_attempt_id:int} $sequences */
    private function write(string $path, array $sequences): void
    {
        try {
            $json = json_encode($sequences, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new CheckoutConsistencyException;
        }

        $temporary = tempnam(dirname($path), '.sequences-');

        if ($temporary === false) {
            throw new CheckoutConsistencyException;
        }

        $handle = @fopen($temporary, 'wb');

        if ($handle === false) {
            throw new CheckoutConsistencyException;
        }

        try {
            if (@fwrite($handle, $json) !== strlen($json) || ! @fflush($handle)) {
                throw new CheckoutConsistencyException;
            }

            if (function_exists('fsync') && ! @fsync($handle)) {
                throw new CheckoutConsistencyException;
            }
        } finally {
            @fclose($handle);
        }

        $renamed = false;
        $renameError = null;

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $renameError = null;

            set_error_handler(
                static function (int $severity, string $message) use (&$renameError): bool {
                    $renameError = [
                        'severity' => $severity,
                        'message' => $message,
                    ];

                    return true;
                }
            );

            try {
                $renamed = rename($temporary, $path);
            } finally {
                restore_error_handler();
            }

            if ($renamed) {
                break;
            }

            if ($attempt < 5) {
                usleep(100000 * $attempt);

                clearstatcache(true, $path);
                clearstatcache(true, $temporary);
            }
        }

        if (! $renamed) {
            Log::warning('checkout_id_sequence_rename_failed', [
                'source_path' => $temporary,
                'destination_path' => $path,
                'native_error' => $renameError,
                'error_get_last' => error_get_last(),
                'source_exists' => is_file($temporary),
                'destination_exists' => is_file($path),
                'source_size' => is_file($temporary) ? @filesize($temporary) : null,
                'destination_size' => is_file($path) ? @filesize($path) : null,
                'source_perms' => is_file($temporary)
                    ? decoct((int) @fileperms($temporary) & 0777)
                    : null,
                'destination_perms' => is_file($path)
                    ? decoct((int) @fileperms($path) & 0777)
                    : null,
                'directory' => dirname($path),
                'directory_perms' => is_dir(dirname($path))
                    ? decoct((int) @fileperms(dirname($path)) & 0777)
                    : null,
            ]);

            @unlink($temporary);

            throw new CheckoutConsistencyException;
        }
    }

    private function filePath(): string
    {
        $path = $this->path ?? storage_path('app/private/checkout/sequences.json');
        $directory = dirname($path);

        if (
            ! is_dir($directory)
            && ! @mkdir($directory, 0750, true)
            && ! is_dir($directory)
        ) {
            throw new CheckoutConsistencyException;
        }

        return $path;
    }

    private function validate(array $sequences): void
    {
        if (array_keys($sequences) !== self::FIELDS) {
            throw new CheckoutConsistencyException;
        }

        foreach ($sequences as $value) {
            if (! is_int($value) || $value < 1 || $value > 2147483647) {
                throw new CheckoutConsistencyException;
            }
        }
    }
}