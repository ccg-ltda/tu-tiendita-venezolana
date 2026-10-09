<?php

namespace App\Services;

use App\Exceptions\CheckoutPaymentEventException;
use App\Exceptions\CheckoutReservationPlanningException;

/**
 * Compatibility facade for the checkout callers.
 *
 * Checkout is SQL-only.  The facade keeps the existing controller and command
 * contracts while routing every operation to the transactional SQL service.
 */
final class CheckoutWriterGateway
{
    public function __construct(private readonly SqlCheckoutService $sql)
    {
    }

    public function backend(): string
    {
        return 'sql';
    }

    /** @param array<string,mixed> $checkout @return array<string,mixed> */
    public function prepareCheckout(array $checkout): array
    {
        try {
            $items = array_map(static fn (array $item): array => [
                'id' => $item['product_id'] ?? $item['id'] ?? null,
                'qty' => $item['quantity'] ?? $item['qty'] ?? null,
            ], $checkout['items'] ?? []);

            return $this->sql->prepare([
                'items' => $items,
                'customer' => $checkout['customer'] ?? [],
                'coupon_code' => $checkout['coupon_code'] ?? null,
                'idempotency_key' => $checkout['idempotency_key'] ?? null,
            ], $checkout['idempotency_key']);
        } catch (CheckoutReservationPlanningException $exception) {
            throw $this->error($exception->checkoutCode());
        }
    }

    /** @param array<string,mixed> $transaction @return array<string,mixed> */
    public function recordPaymentEvent(array $transaction): array
    {
        try {
            $result = $this->sql->recordPaymentEvent(['transaction' => $transaction]);
        } catch (CheckoutPaymentEventException $exception) {
            throw $this->error($exception->paymentCode());
        }

        if (($result['ok'] ?? false) !== true) {
            throw $this->error((string) ($result['code'] ?? 'PAYMENT_EVENT_REJECTED'));
        }

        return $result['data'] ?? $result;
    }

    /** @return array<int,array<string,mixed>> */
    public function getExpiredReservationCandidates(int $limit = 20): array
    {
        return $this->sql->expiredCandidates($limit);
    }

    /** @param array<int,array<string,mixed>> $releases @return array<string,mixed> */
    public function commitExpiredReservations(array $releases): array
    {
        $result = ['released' => [], 'held' => [], 'review_required' => []];

        foreach ($releases as $release) {
            $outcome = $this->sql->release(
                (string) ($release['reference'] ?? ''),
                (int) ($release['expected_revision'] ?? 0),
                $release['verified_final_attempts'] ?? [],
            );
            $bucket = match ($outcome['result'] ?? null) {
                'RELEASED', 'REPLAY' => 'released',
                'PAYMENT_APPROVED' => 'review_required',
                default => 'held',
            };
            $result[$bucket][] = $outcome;
        }

        return $result;
    }

    /** @return array<string,mixed> */
    public function adminGetOrder(int $orderId): array
    {
        return $this->sql->adminOrder($orderId);
    }

    /** @return array<string,mixed> */
    public function adminUpdateOrderStatus(int $orderId, string $nextStatus, ?int $expectedRevision = null): array
    {
        $result = $this->sql->updateAdminStatus($orderId, $nextStatus);
        if (($result['ok'] ?? false) !== true) {
            throw $this->error((string) ($result['error']['code'] ?? 'ORDER_UPDATE_REJECTED'));
        }

        return $result['data'];
    }

    private function error(string $code): CheckoutGatewayException
    {
        $status = match ($code) {
            'IDEMPOTENCY_PAYLOAD_MISMATCH', 'REVISION_CONFLICT' => 409,
            'PRODUCT_NOT_FOUND' => 404,
            'PRODUCT_INACTIVE', 'INSUFFICIENT_STOCK', 'COUPON_NOT_FOUND', 'COUPON_INACTIVE',
            'COUPON_SCHEDULED', 'COUPON_EXPIRED', 'COUPON_EXHAUSTED', 'COUPON_MINIMUM_NOT_MET',
            'COUPON_INELIGIBLE' => 422,
            default => 503,
        };

        return new CheckoutGatewayException($status, $code);
    }
}
