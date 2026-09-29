<?php

namespace App\Services;

use App\Exceptions\CheckoutReservationPlanningException;
use App\Repositories\CheckoutSheetsRepository;

/**
 * Intentionally unregistered from routes, controller and scheduler.
 * cache=file only protects one Laravel host and cannot coordinate with Apps Script.
 */
final class CheckoutDirectPreparationService
{
    public function __construct(private readonly CheckoutLock $lock, private readonly CheckoutPayloadCanonicalizer $canonicalizer, private readonly CheckoutJournalStore $journal, private readonly CheckoutRecoveryService $recovery, private readonly CheckoutReservationPlanner $planner, private readonly CheckoutReservationWriter $writer, private readonly CheckoutSheetsRepository $sheets) {}

    /** @param array{customer:array<string,string|null>,items:list<array{id:int|string,qty:int|string}>} $checkout @return array{order_id:int,reference:string,status:string,payment_status:string,reservation_status:string,reservation_expires_at:string,total_cop:int,created_at:string,revision:int,idempotency_replayed:bool} */
    public function prepare(array $checkout,string $idempotencyKey): array
    {
        $customer=$checkout['customer']??null;$items=$checkout['items']??null;
        if(!is_array($customer)||!is_array($items))throw new \InvalidArgumentException('Invalid checkout.');
        $canonical=$this->canonicalizer->canonicalize($customer,$items);$keyHash=hash('sha256',$idempotencyKey);
        return $this->lock->run(function() use($customer,$items,$idempotencyKey,$canonical,$keyHash): array {
            $journal=$this->journal->load($keyHash);
            if($journal!==null){if(!hash_equals($journal['payload_hash'],$canonical['payload_hash']))throw new CheckoutReservationPlanningException('IDEMPOTENCY_CONFLICT');$recovered=$this->recovery->recoverLocked($idempotencyKey);if($recovered!==null)return $this->result($recovered,true);}
            $plan=$this->planner->plan($customer,$items,$idempotencyKey,now('UTC'));
            if($plan->idempotencyReplayed)return ['order_id'=>$plan->orderId,'reference'=>$plan->reference,'status'=>'PENDING','payment_status'=>'PENDING','reservation_status'=>'ACTIVE','reservation_expires_at'=>$plan->reservationExpiresAt,'total_cop'=>$plan->totalCop,'created_at'=>$plan->createdAt,'revision'=>1,'idempotency_replayed'=>true];
            return $this->result($this->writer->executeLocked($plan),false);
        });
    }
    /** @param array<string,mixed> $order @return array{order_id:int,reference:string,status:string,payment_status:string,reservation_status:string,reservation_expires_at:string,total_cop:int,created_at:string,revision:int,idempotency_replayed:bool} */
    private function result(array $order,bool $replayed): array
    { return ['order_id'=>$order['order_id'],'reference'=>$order['reference'],'status'=>$order['status'],'payment_status'=>$order['payment_status'],'reservation_status'=>$order['reservation_status'],'reservation_expires_at'=>$order['reservation_expires_at'],'total_cop'=>$order['total_cop'],'created_at'=>$order['created_at'],'revision'=>$order['revision'],'idempotency_replayed'=>$replayed]; }
}
