<?php

namespace App\Services;

use App\Exceptions\CheckoutConsistencyException;
use App\Exceptions\CheckoutLockTimeoutException;
use App\Exceptions\CheckoutPaymentEventException;
use App\Repositories\CheckoutSheetsRepository;

/**
 * Disconnected direct counterpart of registrarEventoPago_. No controller,
 * route, scheduler, or production binding invokes this service.
 */
final class CheckoutPaymentEventService
{
    public function __construct(private readonly CheckoutLock $lock,private readonly CheckoutSheetsRepository $sheets,private readonly CheckoutIdSequenceStore $sequences,private readonly ?CheckoutPaymentEventNormalizer $normalizer=null,private readonly ?CheckoutPaymentEventStateValidator $validator=null,private readonly ?CheckoutPaymentEventPlanner $planner=null) {}
    /** @return array{ok:bool,data?:array<string,mixed>,error?:array{code:string}} */
    public function record(mixed $request,?\DateTimeInterface $now=null):array
    {
        $now=$now??now('UTC');try{$event=($this->normalizer??new CheckoutPaymentEventNormalizer)->normalize($request,$now);}catch(CheckoutPaymentEventException $e){return $this->error($e->paymentCode);}
        try{return $this->lock->run(fn()=> $this->recordLocked($event,$now));}catch(CheckoutLockTimeoutException){return $this->error('LOCK_TIMEOUT');}catch(CheckoutPaymentEventException $e){return $this->error($e->paymentCode);}catch(CheckoutConsistencyException){return $this->error('CONSISTENCY_UNCERTAIN');}catch(\Throwable){return $this->error('INTERNAL_ERROR');}
    }
    /** @param array{id:string,reference:string,status:string,payment_method:string,amount_in_cents:int,currency:string,event_occurred_at:string,event_occurred_ms:int} $event */
    private function recordLocked(array $event,\DateTimeInterface $now):array
    {
        $orders=$this->sheets->readOrdersForPaymentEvent();$corrupt=false;foreach($orders as $row)if(is_string($row['reference']??null)&&trim($row['reference'])===$event['reference']&&$row['reference']!==$event['reference'])$corrupt=true;
        if($corrupt)throw new CheckoutPaymentEventException('CONSISTENCY_UNCERTAIN');$matches=array_values(array_filter($orders,fn(array $row):bool=>($row['reference']??null)===$event['reference']));if($matches===[])throw new CheckoutPaymentEventException('ORDER_NOT_FOUND');if(count($matches)!==1)throw new CheckoutPaymentEventException('CONSISTENCY_UNCERTAIN');
        $order=$matches[0];$validator=$this->validator??new CheckoutPaymentEventStateValidator;$state=$validator->order($order,$event['reference']);$orderId=$order['order_id'];$total=$order['total_cop'];if(!is_int($orderId)||!is_int($total))throw new CheckoutPaymentEventException('CONSISTENCY_UNCERTAIN');if($total*100!==$event['amount_in_cents'])throw new CheckoutPaymentEventException('AMOUNT_MISMATCH');
        $payments=$this->sheets->readPaymentsForPaymentEvent();$transactions=$validator->payments($payments);$existing=$transactions[$event['id']]??null;$nowIso=$now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.v\\Z');
        if($existing===null)return $this->newPayment($order,$state,$payments,$event,$nowIso,$validator);
        return $this->existingPayment($order,$state,$existing,$event,$nowIso,$validator);
    }
    private function newPayment(array $order,array $state,array $payments,array $event,string $now,CheckoutPaymentEventStateValidator $validator):array
    {
        $plan=($this->planner??new CheckoutPaymentEventPlanner)->plan($order,$event,$state,$now);$max=0;foreach($payments as $row)$max=max($max,$row['payment_attempt_id']);$id=$this->sequences->reservePaymentAttemptId($max);
        $payment=[$id,$order['order_id'],$event['id'],$event['status'],$event['payment_method'],$event['amount_in_cents'],'COP',$now,$event['event_occurred_at']];$this->sheets->appendPaymentEventRow($payment);if($plan['changed'])$this->sheets->writePaymentEventOrderRow($order['sheet_row'],$this->orderValues($plan['values']));
        $this->verify($order['sheet_row'],$id,$payment,$this->orderValues($plan['values']),$validator);return $this->success($order['order_id'],$id,false,$plan);
    }
    private function existingPayment(array $order,array $state,array $payment,array $event,string $now,CheckoutPaymentEventStateValidator $validator):array
    {
        if($payment['order_id']!==$order['order_id']||$payment['amount_in_cents']!==$event['amount_in_cents']||$payment['currency']!==$event['currency'])throw new CheckoutPaymentEventException('TRANSACTION_CONFLICT');$prior=$validator->time($payment['updated_at']);$priorMs=(new CheckoutUtcTimestamp)->parse($prior)['epoch_ms'];$id=$payment['payment_attempt_id'];
        if($event['event_occurred_ms']<$priorMs)return $this->success($order['order_id'],$id,false,['event_result'=>'STALE_IGNORED','values'=>$order]);
        $same=$event['event_occurred_ms']===$priorMs;$sameFields=$payment['status']===$event['status']&&trim((string)($payment['payment_method']??''))===$event['payment_method']&&$payment['amount_in_cents']===$event['amount_in_cents']&&$payment['currency']===$event['currency'];if($same&&!$sameFields)throw new CheckoutPaymentEventException('CONSISTENCY_UNCERTAIN');
        $plan=($this->planner??new CheckoutPaymentEventPlanner)->plan($order,$event,$state,$now);
        if($same){if($plan['changed'])$this->sheets->writePaymentEventOrderRow($order['sheet_row'],$this->orderValues($plan['values']));$this->verify($order['sheet_row'],$id,$this->paymentValues($payment),$this->orderValues($plan['values']),$validator);return $this->success($order['order_id'],$id,true,$plan);}
        $effective=$payment['status']==='APPROVED'&&$event['status']!=='APPROVED'?array_replace($event,['status'=>'APPROVED','payment_method'=>$payment['payment_method']]):$event;$values=[$id,$order['order_id'],$event['id'],$effective['status'],$effective['payment_method'],$event['amount_in_cents'],'COP',$payment['created_at'],$event['event_occurred_at']];$this->sheets->writePaymentEventRow($payment['sheet_row'],$values);if($plan['changed'])$this->sheets->writePaymentEventOrderRow($order['sheet_row'],$this->orderValues($plan['values']));$this->verify($order['sheet_row'],$id,$values,$this->orderValues($plan['values']),$validator);return $this->success($order['order_id'],$id,false,$plan);
    }
    private function verify(int $orderRow,int $paymentId,array $expectedPayment,array $expectedOrder,CheckoutPaymentEventStateValidator $validator):void
    { $order=null;foreach($this->sheets->readOrdersForPaymentEvent() as $row)if($row['sheet_row']===$orderRow)$order=$row;$payment=null;foreach($this->sheets->readPaymentsForPaymentEvent() as $row)if(($row['payment_attempt_id']??null)===$paymentId)$payment=$row;if($order===null||$payment===null||!$this->same($payment,$expectedPayment,self::PAYMENT_HEADERS)||!$this->same($order,$expectedOrder,self::ORDER_HEADERS))throw new CheckoutPaymentEventException('CONSISTENCY_UNCERTAIN');$validator->payments($this->sheets->readPaymentsForPaymentEvent()); }
    private const PAYMENT_HEADERS=['payment_attempt_id','order_id','wompi_transaction_id','status','payment_method','amount_in_cents','currency','created_at','updated_at'];
    private const ORDER_HEADERS=['order_id','reference','status','payment_status','reservation_status','reservation_expires_at','paid_at','payment_last_event_at','checkout_idempotency_key','checkout_payload_hash','release_id','release_fingerprint','released_at','customer_name','customer_email','customer_phone','customer_document','address','extra','city','region','postal','total_cop','created_at','updated_at','revision'];
    private function paymentValues(array $payment):array{return array_map(fn($key)=>$payment[$key],self::PAYMENT_HEADERS);}
    private function orderValues(array $order):array{return array_map(fn($key)=>$order[$key],self::ORDER_HEADERS);}
    private function same(array $actual,array $expected,array $headers):bool{foreach($headers as $i=>$header)if(($actual[$header]??null)!==$expected[$i])return false;return true;}
    private function success(int $orderId,int $paymentId,bool $replayed,array $plan):array{$v=$plan['values'];return ['ok'=>true,'data'=>['order_id'=>$orderId,'payment_attempt_id'=>$paymentId,'payment_event_replayed'=>$replayed,'event_result'=>$plan['event_result'],'status'=>(string)$v['status'],'payment_status'=>(string)$v['payment_status'],'reservation_status'=>(string)$v['reservation_status'],'revision'=>$v['revision']]];}
    private function error(string $code):array{return ['ok'=>false,'error'=>['code'=>$code]];}
}
