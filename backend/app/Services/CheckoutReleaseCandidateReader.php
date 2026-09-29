<?php

namespace App\Services;

use App\Exceptions\CheckoutConsistencyException;
use App\Repositories\CheckoutSheetsRepository;

/**
 * Read-only literal port of candidatosLiberacionBloqueado_. It is deliberately
 * disconnected from the production scheduler until the coordinated cutover.
 */
final class CheckoutReleaseCandidateReader
{
    private const GRACE_MINUTES = 10;
    private const PAYMENT_STATUSES = ['PENDING', 'APPROVED', 'DECLINED', 'VOIDED', 'ERROR'];

    public function __construct(private readonly CheckoutSheetsRepository $sheets, private readonly ?CheckoutUtcTimestamp $timestamps = null)
    {
    }

    /**
     * @return list<array{order_id:int,reference:string,revision:int,reservation_expires_at:string,total_cop:int,payment_attempts:list<array{wompi_transaction_id:string,status:string,amount_in_cents:int,currency:string,updated_at:string}>}>
     */
    public function candidates(int $limit = 20, ?\DateTimeInterface $now = null): array
    {
        if ($limit < 1 || $limit > 50)
            throw new \InvalidArgumentException('Release candidate limit must be between 1 and 50.');
        $orders = $this->sheets->readOrdersForReleaseCandidates();
        $payments = $this->sheets->readPaymentsForReleaseCandidates();
        $this->validateGlobalState($orders, $payments);
        $byOrder = [];
        foreach ($payments as $payment)
            $byOrder[$payment['order_id']][] = $payment;
        $nowMs = $this->nowMs($now ?? now('UTC'));
        $candidates = [];
        foreach ($orders as $order) {
            $attempts = $byOrder[$order['order_id']] ?? [];
            if ($order['status'] !== 'PENDING' || $order['payment_status'] !== 'PENDING' || $order['reservation_status'] !== 'ACTIVE' || !$this->expiredWithGrace($order['reservation_expires_at'], $nowMs))
                continue;
            $hasApproved = false;
            foreach ($attempts as $attempt) {
                if ($attempt['status'] === 'APPROVED') {
                    $hasApproved = true;
                    break;
                }
            }
            if ($hasApproved)
                continue;
            if (count($candidates) >= $limit)
                continue;
            $candidates[] = ['order_id' => $order['order_id'], 'reference' => $order['reference'], 'revision' => $order['revision'], 'reservation_expires_at' => $this->timestamp()->parse($order['reservation_expires_at'])['iso'], 'total_cop' => $order['total_cop'], 'payment_attempts' => array_map(fn(array $attempt): array => ['wompi_transaction_id' => $attempt['wompi_transaction_id'], 'status' => $attempt['status'], 'amount_in_cents' => $attempt['amount_in_cents'], 'currency' => $attempt['currency'], 'updated_at' => $this->timestamp()->parse($attempt['updated_at'])['iso']], $attempts)];
        }
        return $candidates;
    }

    /** @param list<array<string,mixed>> $orders @param list<array<string,mixed>> $payments */
    private function validateGlobalState(array $orders, array $payments): void
    {
        $orderIds = [];
        $references = [];
        foreach ($orders as $order) {
            $id = $this->positiveInteger($order['order_id'] ?? null, 1);
            $reference = $this->storedText($order['reference'] ?? null, 1, 120);
            if (isset($orderIds[$id]) || isset($references[$reference]))
                throw new CheckoutConsistencyException;
            $orderIds[$id] = true;
            $references[$reference] = true;
            $this->validateOrder($order, $reference);
        }
        $paymentIds = [];
        $transactions = [];
        foreach ($payments as $payment) {
            $id = $this->positiveInteger($payment['payment_attempt_id'] ?? null, 1);
            $orderId = $this->positiveInteger($payment['order_id'] ?? null, 1);
            $transaction = $this->storedText($payment['wompi_transaction_id'] ?? null, 1, 200);
            if (isset($paymentIds[$id]) || isset($transactions[$transaction]) || !isset($orderIds[$orderId]))
                throw new CheckoutConsistencyException;
            if (!in_array($payment['status'] ?? null, self::PAYMENT_STATUSES, true) || $this->storedText($payment['payment_method'] ?? null, 1, 100) === '' || $this->positiveInteger($payment['amount_in_cents'] ?? null, 1) < 1 || ($payment['currency'] ?? null) !== 'COP')
                throw new CheckoutConsistencyException;
            $this->requiredTimestamp($payment['created_at'] ?? null);
            $this->requiredTimestamp($payment['updated_at'] ?? null);
            $paymentIds[$id] = true;
            $transactions[$transaction] = true;
        }
    }

    /** @param array<string,mixed> $order */
    private function validateOrder(array $order, string $reference): void
    {
        if ($this->storedText($order['reference'] ?? null, 1, 120) !== $reference)
            throw new CheckoutConsistencyException;
        $this->positiveInteger($order['order_id'] ?? null, 1);
        foreach (['status', 'payment_status', 'reservation_status'] as $field)
            if (!is_string($order[$field] ?? null))
                throw new CheckoutConsistencyException;
        $combination = $order['status'] . '|' . $order['payment_status'] . '|' . $order['reservation_status'];
        $normal = [
            'PENDING|PENDING|ACTIVE',
            'PENDING|APPROVED|CONSUMED',
            'PROCESSING|APPROVED|CONSUMED',
            'READY|APPROVED|CONSUMED',
            'SHIPPED|APPROVED|CONSUMED',
            'DELIVERED|APPROVED|CONSUMED',
            'CANCELLED|APPROVED|CONSUMED',
            'PENDING|PENDING|RELEASED',
            'PAYMENT_REVIEW_REQUIRED|APPROVED|RELEASED',
        ];
        $preparing = $combination === 'RESERVATION_PREPARING||';
        if (!in_array($combination, $normal, true) && !$preparing)
            throw new CheckoutConsistencyException;
        $revision = $this->positiveInteger($order['revision'] ?? null, 0);
        if (($preparing && $revision !== 0) || (!$preparing && $revision < 1))
            throw new CheckoutConsistencyException;
        $reservation = $this->optionalTimestamp($order['reservation_expires_at'] ?? null, $order['status'] === 'PENDING' && $order['reservation_status'] === 'ACTIVE');
        $paid = $this->optionalTimestamp($order['paid_at'] ?? null, $order['payment_status'] === 'APPROVED');
        $lastEvent = $this->optionalTimestamp($order['payment_last_event_at'] ?? null, false);
        $created = $this->requiredTimestamp($order['created_at'] ?? null);
        $updated = $this->requiredTimestamp($order['updated_at'] ?? null);
        if ($created['epoch_ms'] > $updated['epoch_ms'] || ($paid !== null && $created['epoch_ms'] > $paid['epoch_ms']) || ($lastEvent !== null && $created['epoch_ms'] > $lastEvent['epoch_ms']))
            throw new CheckoutConsistencyException;
        if ($order['payment_status'] !== 'APPROVED' && $paid !== null)
            throw new CheckoutConsistencyException;
        if ($order['payment_status'] === 'APPROVED' && $lastEvent === null)
            throw new CheckoutConsistencyException;
        $released = $combination === 'PENDING|PENDING|RELEASED' || $combination === 'PAYMENT_REVIEW_REQUIRED|APPROVED|RELEASED';
        if (!$released) {
            if (($order['release_id'] ?? null) !== '' || ($order['release_fingerprint'] ?? null) !== '' || ($order['released_at'] ?? null) !== '')
                throw new CheckoutConsistencyException;
            return;
        }
        if (!is_string($order['release_id'] ?? null) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $order['release_id']) !== 1 || !is_string($order['release_fingerprint'] ?? null) || preg_match('/^[0-9a-f]{64}$/D', $order['release_fingerprint']) !== 1 || !is_string($order['released_at'] ?? null) || $this->timestamp()->parse($order['released_at'])['iso'] !== $order['released_at'])
            throw new CheckoutConsistencyException;
        $reservation; // Keeps parity with the Apps Script parse, including optional released reservation timestamps.
    }

    /** @return array{iso:string,epoch_ms:int}|null */
    private function optionalTimestamp(mixed $value, bool $required): ?array
    {
        if ($value === '' || $value === null)
            return $required ? throw new CheckoutConsistencyException : null;
        return $this->requiredTimestamp($value);
    }
    /** @return array{iso:string,epoch_ms:int} */
    private function requiredTimestamp(mixed $value): array
    {
        if (!is_string($value))
            throw new CheckoutConsistencyException;
        return $this->timestamp()->parse($value);
    }
    private function storedText(mixed $value, int $min, int $max): string
    {
        if (!is_string($value) || $value !== trim($value) || strlen($value) < $min || strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value))
            throw new CheckoutConsistencyException;
        return $value;
    }
    private function positiveInteger(mixed $value, int $minimum): int
    {
        if (!is_int($value) || $value < $minimum || $value > 2147483647)
            throw new CheckoutConsistencyException;
        return $value;
    }
    private function expiredWithGrace(mixed $value, int $nowMs): bool
    {
        if (!is_string($value))
            throw new CheckoutConsistencyException;
        return $nowMs >= $this->timestamp()->parse($value)['epoch_ms'] + self::GRACE_MINUTES * 60000;
    }
    private function nowMs(\DateTimeInterface $now): int
    {
        return $this->timestamp()->parse($now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.v\\Z'))['epoch_ms'];
    }
    private function timestamp(): CheckoutUtcTimestamp
    {
        return $this->timestamps ?? new CheckoutUtcTimestamp;
    }
}
