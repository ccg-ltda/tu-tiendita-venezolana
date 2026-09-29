<?php

namespace App\Services;

use App\Exceptions\CheckoutConsistencyException;
use App\Exceptions\CheckoutLockTimeoutException;
use App\Repositories\CheckoutSheetsRepository;

/** Disconnected literal counterpart of actualizarEstadoPedidoAdmin_. */
final class CheckoutAdminOrderStatusService
{
    private const STATUSES=['PENDING','PROCESSING','READY','SHIPPED','DELIVERED','CANCELLED'];
    private const HEADERS=['order_id','reference','status','payment_status','reservation_status','reservation_expires_at','paid_at','payment_last_event_at','checkout_idempotency_key','checkout_payload_hash','release_id','release_fingerprint','released_at','customer_name','customer_email','customer_phone','customer_document','address','extra','city','region','postal','total_cop','created_at','updated_at','revision'];

    public function __construct(private readonly CheckoutLock $lock,private readonly CheckoutSheetsRepository $sheets,private readonly ?CheckoutUtcTimestamp $timestamps=null) {}

    /** @return array{ok:bool,data?:array{order_id:int,status:string,updated_at:string,revision:int,idempotency_replayed:bool},error?:array{code:string}} */
    public function update(mixed $request,?\DateTimeInterface $now=null): array
    {
        if(!is_array($request)||!isset($request['order_id'],$request['status'])||!is_int($request['order_id'])||$request['order_id']<1||$request['order_id']>2147483647||!is_string($request['status'])||!in_array($request['status'],self::STATUSES,true))return $this->error('INVALID_REQUEST');
        try{return $this->lock->run(fn():array=>$this->updateLocked($request['order_id'],$request['status'],$now??now('UTC')));}
        catch(CheckoutLockTimeoutException){return $this->error('LOCK_TIMEOUT');}
        catch(CheckoutConsistencyException){return $this->error('CONSISTENCY_UNCERTAIN');}
        catch(\Throwable){return $this->error('INTERNAL_ERROR');}
    }

    /** Caller holds CheckoutLock::NAME before every Sheets read. */
    private function updateLocked(int $orderId,string $target,\DateTimeInterface $now): array
    {
        // The raw reader has no row validation; its only consistency failure
        // here is the Apps Script-equivalent header/schema failure.
        try{$rows=$this->sheets->readOrdersForAdminStatus();}catch(CheckoutConsistencyException){return $this->error('INTERNAL_ERROR');}
        $matches=[];foreach($rows as $row)if($this->storedOrderId($row['order_id']??null)===$orderId)$matches[]=$row;
        if($matches===[])return $this->error('ORDER_NOT_FOUND');
        if(count($matches)!==1)throw new CheckoutConsistencyException;
        $row=$matches[0];$from=$row['status']??null;
        if($from===$target)return $this->success($orderId,$from,$this->timestamp($row['updated_at']??null),$this->revision($row['revision']??null),true);
        if($from==='DELIVERED'||$from==='CANCELLED')return $this->error('INVALID_STATUS_TRANSITION');
        if($target!=='CANCELLED'){
            $flows=['PENDING'=>['PROCESSING'],'PROCESSING'=>['READY'],'READY'=>['SHIPPED','DELIVERED'],'SHIPPED'=>['DELIVERED']];
            if(!is_string($from)||!in_array($target,$flows[$from]??[],true))return $this->error('INVALID_STATUS_TRANSITION');
            if($from==='PENDING'&&($row['payment_status']??null)!=='APPROVED')return $this->error('PAYMENT_NOT_APPROVED');
        }
        $revision=$this->revision($row['revision']??null);$values=array_map(fn(string $field):mixed=>$row[$field],self::HEADERS);
        $values[2]=$target;$values[24]=$now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.v\\Z');$values[25]=$revision+1;
        $after=$this->sheets->writeAdminOrderStatusAfter($row['sheet_row'],$values);
        if($this->storedOrderId($after['order_id']??null)!==$orderId||$after['status']!==$target||$this->timestamp($after['updated_at']??null)!==$values[24]||$this->revision($after['revision']??null)!==$values[25])throw new CheckoutConsistencyException;
        return $this->success($orderId,$target,$values[24],$values[25],false);
    }
    private function storedOrderId(mixed $value): ?int
    { if(is_int($value)&&$value>=1&&$value<=2147483647)return $value;if(is_string($value)&&preg_match('/^(?:0|[1-9][0-9]*)$/D',$value)===1&&strlen($value)<=10&&(strlen($value)<10||strcmp($value,'2147483647')<=0)&&(int)$value>=1)return (int)$value;return null; }
    private function revision(mixed $value): int
    { if(is_int($value)&&$value>=1&&$value<=2147483647)return $value;if(is_string($value)&&preg_match('/^(?:0|[1-9][0-9]*)$/D',$value)===1&&strlen($value)<=10&&(strlen($value)<10||strcmp($value,'2147483647')<=0)&&(int)$value>=1)return (int)$value;throw new CheckoutConsistencyException; }
    private function timestamp(mixed $value): string
    { if(!is_string($value))throw new CheckoutConsistencyException;return ($this->timestamps??new CheckoutUtcTimestamp)->parse($value)['iso']; }
    /** @return array{ok:true,data:array{order_id:int,status:string,updated_at:string,revision:int,idempotency_replayed:bool}} */
    private function success(int $orderId,string $status,string $updatedAt,int $revision,bool $replayed): array
    { return ['ok'=>true,'data'=>['order_id'=>$orderId,'status'=>$status,'updated_at'=>$updatedAt,'revision'=>$revision,'idempotency_replayed'=>$replayed]]; }
    /** @return array{ok:false,error:array{code:string}} */
    private function error(string $code): array { return ['ok'=>false,'error'=>['code'=>$code]]; }
}
