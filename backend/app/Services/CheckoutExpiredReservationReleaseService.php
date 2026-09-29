<?php

namespace App\Services;

use App\Exceptions\CheckoutConsistencyException;
use App\Repositories\CheckoutSheetsRepository;

/** Disconnected until the coordinated writer cutover; it never runs in production today. */
final class CheckoutExpiredReservationReleaseService
{
    private const GRACE_MINUTES=10;
    public function __construct(private readonly CheckoutLock $lock,private readonly CheckoutSheetsRepository $sheets,private readonly CheckoutReleaseJournalStore $journal,private readonly CheckoutReleaseFingerprint $fingerprints,private readonly ?CheckoutUtcTimestamp $timestamps=null,private readonly ?CheckoutReleaseVerificationValidator $verifications=null,private readonly ?CheckoutReleaseDurableMarkerVerifier $durableMarkers=null,private readonly ?CheckoutReleaseFinalOrderVerifier $finalOrders=null,private readonly ?CheckoutReleaseFaultInjector $faults=null) {}
    /** @param list<array{wompi_transaction_id:string,status:string,checked_at:string}> $verified @return array{result:string,reference:string,revision?:int} */
    public function release(string $reference,int $expectedRevision,array $verified,?\DateTimeInterface $now=null):array
    {return $this->lock->run(fn()=> $this->releaseLocked($reference,$expectedRevision,$verified,$now??now('UTC')));}
    private function releaseLocked(string $reference,int $expectedRevision,array $verified,\DateTimeInterface $now):array
    {
        $order=$this->sheets->findOrderByReference($reference);if($order===null)throw new \RuntimeException('ORDER_NOT_FOUND');
        if(($journal=$this->journal->load($reference))!==null)return $this->recover($order,$journal);
        $items=$this->sheets->readOrderItemsByOrderId($order['order_id']);$fingerprint=$this->fingerprints->calculate($order['order_id'],$reference,$expectedRevision,array_map(fn($i)=>['product_id'=>$i['product_id'],'quantity'=>$i['quantity']],$items),$verified);
        if($order['revision']!==$expectedRevision||$order['status']!=='PENDING'||$order['payment_status']!=='PENDING'||$order['reservation_status']!=='ACTIVE'||!$this->expired($order['reservation_expires_at'],$now))return ['result'=>'HELD','reference'=>$reference];
        $payments=$this->sheets->findPaymentsByOrderId($order['order_id']);if(array_filter($payments,fn($p)=>$p['status']==='APPROVED'))return ['result'=>'PAYMENT_APPROVED','reference'=>$reference];
        $plan=$this->plan($order,$items,$payments,$verified,$fingerprint,$now);$this->journal->create($reference,$plan['journal']);$this->faults?->after('release.after-journal-prepared');
        return $this->recover($order,$plan['journal']);
    }
    /** @param array<string,mixed> $order @param array<string,mixed> $journal */
    private function recover(array $order,array $journal):array
    {
        $expected=$journal['expected_order'];$durable=$this->durableMarkers??new CheckoutReleaseDurableMarkerVerifier($this->time());$final=$this->finalOrders??new CheckoutReleaseFinalOrderVerifier($this->time());
        /* Apps Script replay uses pedidoCoincideLiberacionDurable_, not its
           stricter final write verification. Do not silently strengthen it. */
        if($journal['stage']==='FINALIZED'){
            if(!$durable->matches($order,$expected))throw new CheckoutConsistencyException;
            return ['result'=>'REPLAY','reference'=>$journal['reference'],'revision'=>$order['revision']];
        }
        if($this->orderBefore($order,$journal)) {
            // continue through the current journal stage as a genuine BEFORE row
        } elseif($durable->matches($order,$expected)) {
            while($journal['stage']!=='FINALIZED')$journal=$this->journal->advance($journal['reference'],$this->next($journal['stage']));
            return ['result'=>'REPLAY','reference'=>$journal['reference'],'revision'=>$order['revision']];
        } else throw new CheckoutConsistencyException;
        if($journal['stage']==='PREPARED'){$this->completeProducts($journal);$this->faults?->after('release.after-products');$journal=$this->journal->advance($journal['reference'],'PRODUCTS_WRITTEN');$this->faults?->after('release.after-products-written');}
        if($journal['stage']==='PRODUCTS_WRITTEN'){$this->requireProductsFinal($journal);$this->completePayments($journal);$this->faults?->after('release.after-payments');$journal=$this->journal->advance($journal['reference'],'PAYMENTS_WRITTEN');$this->faults?->after('release.after-payments-written');}
        if($journal['stage']==='PAYMENTS_WRITTEN'){$this->requireProductsFinal($journal);$this->requirePaymentsFinal($journal);$this->sheets->writeReleaseOrderAfter($order['sheet_row'],$expected);$this->faults?->after('release.after-order');$order=$this->sheets->readOrderById($order['order_id'])??throw new CheckoutConsistencyException;$this->requireFinal($order,$journal,$final);$journal=$this->journal->advance($journal['reference'],'ORDER_WRITTEN');}
        if($journal['stage']==='ORDER_WRITTEN'){$this->requireProductsFinal($journal);$this->requirePaymentsFinal($journal);$this->requireFinal($order,$journal,$final);$this->journal->advance($journal['reference'],'FINALIZED');$this->faults?->after('release.after-finalized');}
        return ['result'=>'RELEASED','reference'=>$journal['reference'],'revision'=>$expected['revision_after']];
    }
    /** Apps Script product recovery classifies each row by inventory+revision only. */
    private function completeProducts(array $journal):void
    { $actual=$this->sheets->readProductsByIds(array_column($journal['products'],'product_id'));$by=[];foreach($actual as $row)$by[$row['product_id']]=$row;$classifier=new CheckoutReleaseProductStateClassifier($this->time());foreach($journal['products'] as $index=>$change){$row=$by[$change['product_id']]??null;if($row===null||!in_array($classifier->classify($row,$change),['BEFORE','AFTER'],true))throw new CheckoutConsistencyException;$this->sheets->writeReleaseInventoryAfter([$change]);if($index===0)$this->faults?->after('release.after-first-product');}$this->requireProductsFinal($journal); }
    private function completePayments(array $journal):void
    { $all=$this->sheets->readPayments();$by=[];foreach($all as $row)$by[$row['payment_attempt_id']]=$row;$classifier=new CheckoutReleasePaymentStateClassifier($this->time());foreach($journal['payments'] as $change){$row=$by[$change['payment_attempt_id']]??null;if($row===null||!in_array($classifier->classify($row,$change),['BEFORE','AFTER'],true))throw new CheckoutConsistencyException;$this->sheets->writeReleasePaymentAfter($change);}$this->requirePaymentsFinal($journal); }
    private function requireProductsFinal(array $journal):void
    { $rows=$this->sheets->readProductsByIds(array_column($journal['products'],'product_id'));$by=[];foreach($rows as $row)$by[$row['product_id']]=$row;foreach($journal['products'] as $p){$row=$by[$p['product_id']]??null;if($row===null||$row['sheet_row']!==$p['row_number']||$row['inventory']!==$p['inventory_after']||$row['revision']!==$p['revision_after']||$row['updated_at']!==$p['updated_at_after'])throw new CheckoutConsistencyException;} }
    private function requirePaymentsFinal(array $journal):void
    { $rows=$this->sheets->readPayments();$by=[];foreach($rows as $row)$by[$row['payment_attempt_id']]=$row;$classifier=new CheckoutReleasePaymentStateClassifier($this->time());foreach($journal['payments'] as $p)if(!isset($by[$p['payment_attempt_id']])||$classifier->classify($by[$p['payment_attempt_id']],$p)!=='AFTER')throw new CheckoutConsistencyException; }
    private function requireFinal(array $order,array $journal,CheckoutReleaseFinalOrderVerifier $final):void
    { $this->requireProductsFinal($journal);$this->requirePaymentsFinal($journal);if(!$final->matches($order,$journal['expected_order']))throw new CheckoutConsistencyException; }
    private function orderBefore(array $order,array $journal):bool
    { return (new CheckoutReleaseOrderBeforeVerifier($this->time()))->matches($order,$journal['expected_order']); }
    private function next(string $stage):string{return ['PREPARED'=>'PRODUCTS_WRITTEN','PRODUCTS_WRITTEN'=>'PAYMENTS_WRITTEN','PAYMENTS_WRITTEN'=>'ORDER_WRITTEN','ORDER_WRITTEN'=>'FINALIZED'][$stage]??throw new CheckoutConsistencyException;}
    /** @return array{journal:array<string,mixed>} */
    private function plan(array $order,array $items,array $payments,array $verified,string $fingerprint,\DateTimeInterface $now):array
    {
        if($items===[])throw new CheckoutConsistencyException;$verifiedBy=($this->verifications??new CheckoutReleaseVerificationValidator($this->time()))->validate($payments,$verified,$now);$timestamp=$now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.v\\Z');$qty=[];foreach($items as $i)$qty[$i['product_id']]=($qty[$i['product_id']]??0)+$i['quantity'];$products=[];foreach($this->sheets->readProductsByIds(array_keys($qty)) as $p){$after=$p['inventory']+$qty[$p['product_id']];if($after>2147483647)throw new CheckoutConsistencyException;$products[]=['product_id'=>$p['product_id'],'row_number'=>$p['sheet_row'],'inventory_before'=>$p['inventory'],'inventory_after'=>$after,'revision_before'=>$p['revision'],'revision_after'=>$p['revision']+1,'updated_at_before'=>$p['updated_at']===''?null:$p['updated_at'],'updated_at_after'=>$timestamp];}if(count($products)!==count($qty))throw new CheckoutConsistencyException;
        $paymentPlan=[];foreach($payments as $p)if($p['status']==='PENDING'){$v=$verifiedBy[$p['wompi_transaction_id']];$paymentPlan[]=['row_number'=>$p['sheet_row'],'payment_attempt_id'=>$p['payment_attempt_id'],'order_id'=>$p['order_id'],'wompi_transaction_id'=>$p['wompi_transaction_id'],'status_before'=>'PENDING','status_after'=>$v['status'],'payment_method'=>$p['payment_method'],'amount_in_cents'=>$p['amount_in_cents'],'currency'=>$p['currency'],'created_at'=>$p['created_at'],'updated_at_before'=>$p['updated_at'],'updated_at_after'=>$v['checked_at']];}
        $id=(string)\Illuminate\Support\Str::uuid();$expected=['order_id'=>$order['order_id'],'reference'=>$order['reference'],'status_before'=>'PENDING','status_after'=>'PENDING','payment_status_before'=>'PENDING','payment_status_after'=>'PENDING','reservation_status_before'=>'ACTIVE','reservation_status_after'=>'RELEASED','revision_before'=>$order['revision'],'revision_after'=>$order['revision']+1,'release_id'=>$id,'release_fingerprint'=>$fingerprint,'released_at_after'=>$timestamp,'updated_at_after'=>$timestamp,'paid_at_after'=>($order['paid_at']??null)===''?null:($order['paid_at']??null),'payment_last_event_at_after'=>($order['payment_last_event_at']??null)===''?null:($order['payment_last_event_at']??null),'total_cop'=>$order['total_cop']];
        return ['journal'=>['version'=>2,'reference'=>$order['reference'],'order_id'=>$order['order_id'],'expected_revision'=>$order['revision'],'release_id'=>$id,'release_fingerprint'=>$fingerprint,'stage'=>'PREPARED','created_at'=>$timestamp,'updated_at'=>$timestamp,'products'=>$products,'payments'=>$paymentPlan,'expected_order'=>$expected]];
    }
    private function expired(mixed $expires,\DateTimeInterface $now):bool{if(!is_string($expires))throw new CheckoutConsistencyException;$expiry=$this->time()->parse($expires)['epoch_ms'];$current=(int)$now->format('U')*1000+(int)$now->format('v');return $current>=$expiry+self::GRACE_MINUTES*60000;}
    private function time():CheckoutUtcTimestamp{return $this->timestamps??new CheckoutUtcTimestamp;}
}
