<?php

namespace App\Data;

final readonly class CheckoutReservationPlan
{
    /**
     * @param array{name:string,email:string,phone:string,document:string,address:string,extra:string|null,city:string,region:string,postal:string|null} $customer
     * @param list<array{product_id:int,quantity:int}> $items
     * @param list<array{product_id:int,row_number:int,inventory_before:int,inventory_after:int,revision_before:int,revision_after:int}> $inventoryPlan
     * @param list<mixed> $orderValues
     * @param list<list<mixed>> $orderItemRows
     */
    public function __construct(
        public bool $idempotencyReplayed,
        public array $customer,
        public array $items,
        public string $idempotencyKey,
        public string $payloadHash,
        public int $orderId,
        public int $firstOrderItemId,
        public string $reference,
        public int $totalCop,
        public string $reservationExpiresAt,
        public string $createdAt,
        public array $inventoryPlan,
        public ?int $orderRow,
        public array $orderValues,
        public array $orderItemRows,
    ) {}
}
