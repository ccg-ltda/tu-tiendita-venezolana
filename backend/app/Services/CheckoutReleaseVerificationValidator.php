<?php
namespace App\Services;
use App\Exceptions\CheckoutConsistencyException;
use App\Exceptions\CheckoutReleaseException;
final class CheckoutReleaseVerificationValidator
{
    public function __construct(private readonly CheckoutUtcTimestamp $timestamps) {}
    /** @param list<array<string,mixed>> $payments @param list<array{wompi_transaction_id:string,status:string,checked_at:string}> $verified @return array<string,array{status:string,checked_at:string}> */
    public function validate(array $payments,array $verified,\DateTimeInterface $now):array
    { $out=[];$nowMs=(int)$now->format('U')*1000+(int)$now->format('v');foreach($verified as $v){if(!is_string($v['wompi_transaction_id']??null)||trim($v['wompi_transaction_id'])!==$v['wompi_transaction_id']||$v['wompi_transaction_id']===''||strlen($v['wompi_transaction_id'])>200||!in_array($v['status']??null,['DECLINED','VOIDED','ERROR'],true)||isset($out[$v['wompi_transaction_id']]))throw new CheckoutReleaseException('INVALID_REQUEST');try{$at=$this->timestamps->parse($v['checked_at']);}catch(CheckoutConsistencyException){throw new CheckoutReleaseException('INVALID_REQUEST');}if($at['epoch_ms']>$nowMs+600000)throw new CheckoutReleaseException('INVALID_REQUEST');$out[$v['wompi_transaction_id']]=['status'=>$v['status'],'checked_at'=>$at['iso']];}
      $pending=[];foreach($payments as $p)if($p['status']==='PENDING'){$pending[$p['wompi_transaction_id']]=$p;}foreach($out as $id=>$v){$payment=$pending[$id]??null;if($payment===null)throw new CheckoutConsistencyException;if($this->timestamps->parse($v['checked_at'])['epoch_ms']<$this->timestamps->parse($payment['updated_at'])['epoch_ms'])throw new CheckoutConsistencyException;}foreach($pending as $id=>$p)if(!isset($out[$id]))throw new CheckoutReleaseException('PENDING_PAYMENT_UNVERIFIED');return $out; }
}
