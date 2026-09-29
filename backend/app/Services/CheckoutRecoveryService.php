<?php

namespace App\Services;

use App\Exceptions\CheckoutConsistencyException;
use App\Repositories\CheckoutSheetsRepository;

/** Technical recovery only; it never performs ambiguous-reservation renewal. */
final class CheckoutRecoveryService
{
    public function __construct(private readonly CheckoutLock $lock, private readonly CheckoutJournalStore $journal, private readonly CheckoutSheetsRepository $sheets) {}

    /** @return array<string,mixed>|null Null means a PREPARED operation was safely abandoned before writes. */
    public function recover(string $idempotencyKey): ?array
    { return $this->lock->run(fn (): ?array => $this->recoverLocked($idempotencyKey)); }

    /** Caller must already hold CheckoutLock. */
    public function recoverLocked(string $idempotencyKey): ?array
    {
        $keyHash=hash('sha256',$idempotencyKey);$journal=$this->journal->load($keyHash);if($journal===null)return null;
        $order=$this->sheets->readOrderById($journal['order_id']);$items=$this->sheets->readOrderItemsByOrderId($journal['order_id']);$products=$this->sheets->readProductsByIds(array_column($journal['inventory_plan'],'product_id'));
        if($order===null){if($journal['stage']!=='PREPARED'||$items!==[]||!$this->allProducts($products,$journal['inventory_plan'],'before'))throw new CheckoutConsistencyException;$this->journal->delete($keyHash);return null;}
        $this->assertOrderIdentity($order,$journal);
        if($this->isFinal($order)){$this->assertItems($items,$journal);$this->journal->delete($keyHash);return $order;}
        if($order['status']!=='RESERVATION_PREPARING'||$order['payment_status']!==''||$order['reservation_status']!==''||$order['revision']!==0)throw new CheckoutConsistencyException;
        if($items===[]){$this->sheets->appendOrderItems($journal['order_item_rows']);$items=$this->sheets->readOrderItemsByOrderId($journal['order_id']);}
        $this->assertItems($items,$journal);
        $before=$this->allProducts($products,$journal['inventory_plan'],'before');$after=$this->allProducts($products,$journal['inventory_plan'],'after');
        if(!$before&&!$after)throw new CheckoutConsistencyException;
        $this->advanceTo($keyHash,$journal,'ITEMS_WRITTEN');
        if($before){$this->sheets->applyInventoryPlan($journal['inventory_plan']);}
        $this->advanceTo($keyHash,$journal,'PRODUCTS_WRITTEN');
        $final=$this->finalValues($order,$journal);$written=$this->sheets->writeFinalOrder($order['sheet_row'],$final);
        $this->journal->markFinalized($keyHash);$this->journal->delete($keyHash);return $written;
    }
    /** @param array<string,mixed> $order @param array<string,mixed> $journal */
    private function assertOrderIdentity(array $order,array $journal): void
    { if($order['reference']!==$journal['reference']||hash('sha256',$order['checkout_idempotency_key'])!==$journal['idempotency_key_hash']||$order['checkout_payload_hash']!==$journal['payload_hash'])throw new CheckoutConsistencyException; }
    /** @param array<string,mixed> $order */
    private function isFinal(array $order): bool
    { return $order['status']==='PENDING'&&$order['payment_status']==='PENDING'&&$order['reservation_status']==='ACTIVE'&&$order['revision']===1; }
    /** @param list<array<string,mixed>> $items @param array<string,mixed> $journal */
    private function assertItems(array $items,array $journal): void
    { if(count($items)!==$journal['item_count'])throw new CheckoutConsistencyException;$expected=[];foreach($journal['inventory_plan'] as $index=>$plan)$expected[$journal['first_order_item_id']+$index]=$plan;foreach($items as $item){$plan=$expected[$item['order_item_id']]??null;if($plan===null||$item['product_id']!==$plan['product_id']||$item['quantity']!==$plan['inventory_before']-$plan['inventory_after']||$item['unit_price_cop']<1||trim($item['product_name'])==='')throw new CheckoutConsistencyException;unset($expected[$item['order_item_id']]);}if($expected!==[])throw new CheckoutConsistencyException; }
    /** @param list<array<string,mixed>> $products @param list<array<string,mixed>> $plan */
    private function allProducts(array $products,array $plan,string $state): bool
    { if(count($products)!==count($plan))return false;$by=[];foreach($products as $product)$by[$product['product_id']]=$product;foreach($plan as $change){$product=$by[$change['product_id']]??null;if($product===null||$product['sheet_row']!==$change['row_number']||$product['inventory']!==$change['inventory_'.$state]||$product['revision']!==$change['revision_'.$state])return false;}return true; }
    /** @param array<string,mixed> $technical @param array<string,mixed> $journal @return list<mixed> */
    private function finalValues(array $technical,array $journal): array
    { $headers=['order_id','reference','status','payment_status','reservation_status','reservation_expires_at','paid_at','payment_last_event_at','checkout_idempotency_key','checkout_payload_hash','release_id','release_fingerprint','released_at','customer_name','customer_email','customer_phone','customer_document','address','extra','city','region','postal','total_cop','created_at','updated_at','revision'];$values=array_map(fn(string $header):mixed=>$technical[$header],$headers);$values[2]='PENDING';$values[3]='PENDING';$values[4]='ACTIVE';$values[5]=$journal['reservation_expires_at'];$values[25]=1;return $values; }
    /** @param array<string,mixed> $journal */
    private function advanceTo(string $keyHash,array &$journal,string $target): void
    { $stages=['PREPARED','ORDER_WRITTEN','ITEMS_WRITTEN','PRODUCTS_WRITTEN'];$from=array_search($journal['stage'],$stages,true);$to=array_search($target,$stages,true);if($from===false||$to===false||$from>$to)throw new CheckoutConsistencyException;for($index=$from+1;$index<=$to;$index++){$journal=$this->journal->advanceStage($keyHash,$stages[$index]);} }
}
