<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\CheckoutWriterGateway;
use App\Services\AppsScriptCheckoutException;
use App\Services\WompiPaymentEventOutboxStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WompiWebhookController extends Controller
{
    private const EVENT = 'transaction.updated';

    /** @var list<string> */
    private const TRANSACTION_STATUSES = ['PENDING', 'APPROVED', 'DECLINED', 'VOIDED', 'ERROR'];

    public function handle(Request $request, CheckoutWriterGateway $client, WompiPaymentEventOutboxStore $outbox): JsonResponse
    {
        $eventsSecret = config('services.wompi.events_secret');
        if (! is_string($eventsSecret) || $eventsSecret === '') {
            Log::error('Wompi webhook ignored because its event configuration is unavailable.');

            return response()->json(['error' => 'Webhook configuration unavailable.'], 503);
        }

        $payload = $request->json()->all();
        if ($this->invalidPayload($payload)) {
            return response()->json(['error' => 'Invalid webhook payload.'], 422);
        }

        /** @var array{event: string, timestamp: int, signature: array{properties: list<string>, checksum: string}, data: array{transaction: array<string, mixed>}} $payload */
        if ($payload['event'] !== self::EVENT) {
            Log::notice('Unsupported Wompi webhook event received.', ['event' => $payload['event']]);

            return response()->json(['error' => 'Unsupported webhook event.'], 422);
        }

        $checksum = $payload['signature']['checksum'];
        $headerChecksum = $request->header('X-Event-Checksum');
        if (is_string($headerChecksum) && $headerChecksum !== '' && ! hash_equals($checksum, $headerChecksum)) {
            return response()->json(['error' => 'Invalid webhook signature.'], 401);
        }

        $signedValues = $this->signedValues($payload['data'], $payload['signature']['properties']);
        if ($signedValues === null) {
            return response()->json(['error' => 'Invalid webhook payload.'], 422);
        }

        // Wompi specifies: received property values + timestamp + event secret.
        $expectedChecksum = hash('sha256', $signedValues.(string) $payload['timestamp'].$eventsSecret);
        if (! hash_equals($checksum, $expectedChecksum)) {
            return response()->json(['error' => 'Invalid webhook signature.'], 401);
        }

        $transaction = $payload['data']['transaction'];
        $eventOccurredAt = $this->eventOccurredAt($payload['timestamp']);
        if ($eventOccurredAt === null) {
            return response()->json(['error' => 'Invalid webhook payload.'], 422);
        }

        $event = [
            'id' => $transaction['id'],
            'reference' => $transaction['reference'],
            'status' => $transaction['status'],
            'payment_method' => $this->paymentMethod($transaction),
            'amount_in_cents' => $transaction['amount_in_cents'],
            'currency' => $transaction['currency'],
            'event_occurred_at' => $eventOccurredAt,
        ];

        try {
            $result = $client->recordPaymentEvent($event);
        } catch (AppsScriptCheckoutException $exception) {
            $status = $exception->status();
            if (in_array($status, [502, 503, 504], true)) {
                $this->enqueueForRetry($outbox, $event, $transaction['id']);
            }
            Log::warning('Wompi webhook could not be persisted in checkout storage.', [
                'transaction_id' => $transaction['id'],
                'remote_code' => $exception->remoteCode(),
                'status' => $status,
            ]);

            return response()->json(['error' => $this->processingError($status)], $status);
        } catch (\Throwable) {
            $this->enqueueForRetry($outbox, $event, $transaction['id']);
            Log::error('Wompi webhook could not be processed.');

            return response()->json(['error' => 'Webhook processing unavailable.'], 503);
        }

        try {
            $outbox->remove($event['id']);
        } catch (\Throwable) {
            Log::error('Persisted Wompi payment event could not be removed from the outbox.', ['transaction_id' => $event['id']]);
        }

        Log::info('Wompi webhook processed.', [
            'transaction_id' => $transaction['id'],
            'status' => $transaction['status'],
            'result' => $result['event_result'],
        ]);

        return response()->json(['status' => 'ok']);
    }

    /** @param array{id:string,reference:string,status:string,payment_method:string,amount_in_cents:int,currency:string,event_occurred_at:string} $event */
    private function enqueueForRetry(WompiPaymentEventOutboxStore $outbox, array $event, string $transactionId): void
    {
        try {
            $outbox->enqueue($event);
        } catch (\Throwable) {
            Log::error('Wompi payment event could not be written to the durable outbox.', ['transaction_id' => $transactionId]);
        }
    }

    private function invalidPayload(mixed $payload): bool
    {
        if (! is_array($payload) || ! is_string($payload['event'] ?? null) || ! is_int($payload['timestamp'] ?? null) || $payload['timestamp'] < 0) {
            return true;
        }

        $signature = $payload['signature'] ?? null;
        $transaction = $payload['data']['transaction'] ?? null;
        if (! is_array($signature) || ! is_array($signature['properties'] ?? null) || ! is_string($signature['checksum'] ?? null)
            || $signature['checksum'] === '' || ! is_array($transaction)) {
            return true;
        }

        foreach ($signature['properties'] as $property) {
            if (! is_string($property) || $property === '') {
                return true;
            }
        }

        return ! is_string($transaction['id'] ?? null)
            || trim($transaction['id']) === ''
            || ! is_string($transaction['reference'] ?? null)
            || trim($transaction['reference']) === ''
            || ! is_string($transaction['status'] ?? null)
            || ! in_array($transaction['status'], self::TRANSACTION_STATUSES, true)
            || ! is_int($transaction['amount_in_cents'] ?? null)
            || $transaction['amount_in_cents'] < 1
            || ! is_string($transaction['currency'] ?? null)
            || $transaction['currency'] !== 'COP';
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $properties
     */
    private function signedValues(array $data, array $properties): ?string
    {
        $values = '';
        foreach ($properties as $property) {
            $value = $this->valueAtPath($data, $property);
            if ($value === null || is_array($value) || is_object($value)) {
                return null;
            }
            $values .= (string) $value;
        }

        return $values;
    }

    /** @param array<string, mixed> $data */
    private function valueAtPath(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if ($segment === '' || ! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** @param array<string, mixed> $transaction */
    private function paymentMethod(array $transaction): string
    {
        $method = $transaction['payment_method_type'] ?? null;

        // Apps Script requires a non-empty technical value; Wompi may omit it on early events.
        return is_string($method) && trim($method) !== '' ? trim($method) : 'UNKNOWN';
    }

    private function eventOccurredAt(int $timestamp): ?string
    {
        try {
            $millisecondsTimestamp = $timestamp >= 100_000_000_000;
            $seconds = $millisecondsTimestamp ? intdiv($timestamp, 1000) : $timestamp;
            $milliseconds = $millisecondsTimestamp ? $timestamp % 1000 : 0;
            $date = (new \DateTimeImmutable('@'.$seconds))->setTimezone(new \DateTimeZone('UTC'));

            return $date->format('Y-m-d\\TH:i:s.').str_pad((string) $milliseconds, 3, '0', STR_PAD_LEFT).'Z';
        } catch (\Throwable) {
            return null;
        }
    }

    private function processingError(int $status): string
    {
        return match ($status) {
            400 => 'Invalid webhook transaction.',
            404 => 'Order not found.',
            409, 422 => 'Webhook transaction conflict.',
            default => 'Webhook processing unavailable.',
        };
    }
}
