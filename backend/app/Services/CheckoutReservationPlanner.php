<?php

namespace App\Services;

use App\Data\CheckoutReservationPlan;
use App\Exceptions\CheckoutConsistencyException;
use App\Exceptions\CheckoutReservationPlanningException;
use App\Repositories\CheckoutSheetsRepository;
use DateTimeInterface;

final class CheckoutReservationPlanner
{
    private const RESERVATION_MINUTES = 10;

    public function __construct(
        private readonly CheckoutSheetsRepository $sheets,
        private readonly CheckoutPayloadCanonicalizer $canonicalizer,
        private readonly CheckoutReferenceGenerator $references,
        private readonly CheckoutIdSequenceStore $sequences,
    ) {}

    /** @param array{name:string,email:string,phone:string,document:string,address:string,extra:string|null,city:string,region:string,postal:string|null} $customer @param list<array{id:int|string,qty:int|string}> $items */
    public function plan(array $customer, array $items, string $idempotencyKey, DateTimeInterface $now): CheckoutReservationPlan
    {
        $canonical = $this->canonicalizer->canonicalize($customer, $items);
        $orders = $this->sheets->readOrders();
        $existing = $this->findOrder($orders, $idempotencyKey);
        if ($existing !== null) return $this->replay($existing, $customer, $canonical, $now);

        $products = $this->sheets->readProducts();
        $orderItems = $this->sheets->readOrderItems();
        $payments = $this->sheets->readPayments();
        $this->sequences->initializeFromMaxima($this->maximum($orders, 'order_id'), $this->maximum($orderItems, 'order_item_id'), $this->maximum($payments, 'payment_attempt_id'));
        $orderId = $this->sequences->reserveOrderId($this->maximum($orders, 'order_id'));
        $firstItemId = $this->sequences->reserveOrderItemIds(count($canonical['items']), $this->maximum($orderItems, 'order_item_id'));
        $createdAt = $this->timestamp($now);
        $expiresAt = $this->timestamp(\DateTimeImmutable::createFromInterface($now)->setTimezone(new \DateTimeZone('UTC'))->modify('+'.self::RESERVATION_MINUTES.' minutes'));
        $reference = $this->references->generate($orderId, $now);
        $byProduct=[]; foreach ($products as $product) $byProduct[$product['product_id']] = $product;
        $inventory=[]; $itemRows=[]; $total=0;
        foreach ($canonical['items'] as $index=>$item) {
            $product = $byProduct[$item['product_id']] ?? null;
            if ($product === null) throw new CheckoutReservationPlanningException('PRODUCT_NOT_FOUND');
            if (! $product['active']) throw new CheckoutReservationPlanningException('PRODUCT_INACTIVE');
            if ($product['price_cop'] < 1) throw new CheckoutReservationPlanningException('INVALID_PRODUCT_PRICE');
            if ($product['inventory'] < $item['quantity']) throw new CheckoutReservationPlanningException('INSUFFICIENT_STOCK');
            $lineTotal = $product['price_cop'] * $item['quantity'];
            if ($lineTotal > PHP_INT_MAX - $total) throw new CheckoutReservationPlanningException('INVALID_REQUEST');
            $total += $lineTotal;
            $inventory[]=['product_id'=>$product['product_id'],'row_number'=>$product['sheet_row'],'inventory_before'=>$product['inventory'],'inventory_after'=>$product['inventory']-$item['quantity'],'revision_before'=>$product['revision'],'revision_after'=>$product['revision']+1];
            $itemRows[] = [$firstItemId+$index,$orderId,$product['product_id'],$product['name'],$product['price_cop'],$item['quantity'],$createdAt];
        }
        $orderValues = [$orderId,$reference,'PENDING','PENDING','ACTIVE',$expiresAt,'','',$idempotencyKey,$canonical['payload_hash'],'','','',$customer['name'],$customer['email'],$customer['phone'],$customer['document'],$customer['address'],$customer['extra'] ?? '',$customer['city'],$customer['region'],$customer['postal'] ?? '',$total,$createdAt,$createdAt,1];
        $orderRow = $orders === [] ? 2 : max(array_column($orders, 'sheet_row')) + 1;
        return new CheckoutReservationPlan(false, $customer, $canonical['items'], $idempotencyKey, $canonical['payload_hash'], $orderId, $firstItemId, $reference, $total, $expiresAt, $createdAt, $inventory, $orderRow, $orderValues, $itemRows);
    }

    /** @param list<array<string,mixed>> $orders @return array<string,mixed>|null */
    private function findOrder(array $orders, string $idempotencyKey): ?array
    { foreach ($orders as $order) if ($order['checkout_idempotency_key'] === $idempotencyKey) return $order; return null; }
    /** @param array<string,mixed> $order @param array{items:list<array{product_id:int,quantity:int}>,canonical:string,payload_hash:string} $canonical */
    private function replay(array $order, array $customer, array $canonical, DateTimeInterface $now): CheckoutReservationPlan
    {
        if (! hash_equals($order['checkout_payload_hash'], $canonical['payload_hash'])) throw new CheckoutReservationPlanningException('IDEMPOTENCY_CONFLICT');
        if ($order['reservation_status'] === 'RELEASED' || $this->expired($order['reservation_expires_at'], $now)) throw new CheckoutReservationPlanningException('RESERVATION_EXPIRED');
        if ($order['status'] !== 'PENDING' || $order['payment_status'] !== 'PENDING' || $order['reservation_status'] !== 'ACTIVE') throw new CheckoutConsistencyException;
        return new CheckoutReservationPlan(true, $customer, $canonical['items'], $order['checkout_idempotency_key'], $canonical['payload_hash'], $order['order_id'], 0, $order['reference'], $order['total_cop'], $order['reservation_expires_at'], $order['created_at'], [], $order['sheet_row'], [], []);
    }
    /** @param list<array<string,mixed>> $rows */
    private function maximum(array $rows, string $field): int
    { return $rows === [] ? 0 : max(array_column($rows, $field)); }
    private function expired(mixed $value, DateTimeInterface $now): bool
    { if (! is_string($value)) throw new CheckoutConsistencyException; try { return (float) (new \DateTimeImmutable($value))->format('U.u') <= (float) $now->format('U.u'); } catch (\Throwable) { throw new CheckoutConsistencyException; } }
    private function timestamp(DateTimeInterface $time): string
    { return \DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.v\\Z'); }
}
