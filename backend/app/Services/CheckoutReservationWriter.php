<?php

namespace App\Services;

use App\Data\CheckoutReservationPlan;
use App\Repositories\CheckoutSheetsRepository;

/** Must remain unreachable from HTTP until direct checkout replaces Apps Script atomically. */
final class CheckoutReservationWriter
{
    public function __construct(private readonly CheckoutLock $lock, private readonly CheckoutJournalStore $journal, private readonly CheckoutSheetsRepository $sheets, private readonly ?CheckoutWriteFaultInjector $faultInjector=null) {}

    public function execute(CheckoutReservationPlan $plan): array
    { return $this->lock->run(fn (): array => $this->executeLocked($plan)); }

    /** Caller must already hold CheckoutLock. */
    public function executeLocked(CheckoutReservationPlan $plan): array
    {
        $keyHash=hash('sha256',$plan->idempotencyKey);
        $this->journal->createPrepared($keyHash,$plan->payloadHash,$plan->orderId,$plan->firstOrderItemId,count($plan->items),$plan->reference,$plan->reservationExpiresAt,$plan->inventoryPlan,$plan->orderItemRows,$plan->createdAt);
        $this->sheets->writeTechnicalOrder($plan->orderRow ?? throw new \LogicException('New checkout requires an order row.'),$plan->orderValues);
        $this->faultInjector?->after('order');
        $this->journal->advanceStage($keyHash,'ORDER_WRITTEN');
        $this->sheets->appendOrderItems($plan->orderItemRows);
        $this->faultInjector?->after('items');
        $this->journal->advanceStage($keyHash,'ITEMS_WRITTEN');
        $this->sheets->applyInventoryPlan($plan->inventoryPlan);
        $this->faultInjector?->after('products');
        $this->journal->advanceStage($keyHash,'PRODUCTS_WRITTEN');
        $order=$this->sheets->writeFinalOrder($plan->orderRow,$plan->orderValues);
        $this->faultInjector?->after('final');
        $this->journal->markFinalized($keyHash);
        $this->journal->delete($keyHash);
        $this->faultInjector?->after('post_write');
        return $order;
    }
}
