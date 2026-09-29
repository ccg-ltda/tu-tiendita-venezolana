<?php

namespace App\Services;

use App\Exceptions\CheckoutPaymentEventException;

/** Literal validation boundary for validarPedidoEventoPago_ and validarPagosGlobalEventoPago_. */
final class CheckoutPaymentEventStateValidator
{
    private const STATUSES=['PENDING','APPROVED','DECLINED','VOIDED','ERROR'];
    public function __construct(private readonly ?CheckoutUtcTimestamp $timestamps=null) {}
    /** @return array{revision:int,paid_at:?string,payment_last_event_at:?string,combination:string} */
    public function order(array $row,string $reference):array
    {
        if($this->storedText($row['reference']??null,1,500)!==$reference)$this->fail();$this->positive($row['order_id']??null);$this->nonNegative($row['total_cop']??null);$revision=$this->positive($row['revision']??null);
        $combination=(string)($row['status']??'').'|'.(string)($row['payment_status']??'').'|'.(string)($row['reservation_status']??'');
        if(!in_array($combination,['PENDING|PENDING|ACTIVE','PENDING|PENDING|RELEASED','PENDING|APPROVED|CONSUMED','PAYMENT_REVIEW_REQUIRED|APPROVED|RELEASED'],true))$this->fail();
        $paid=$this->optionalTime($row['paid_at']??null);$last=$this->optionalTime($row['payment_last_event_at']??null);
        if(in_array($combination,['PENDING|PENDING|ACTIVE','PENDING|PENDING|RELEASED'],true)&&$paid!==null)$this->fail();
        if(in_array($combination,['PENDING|APPROVED|CONSUMED','PAYMENT_REVIEW_REQUIRED|APPROVED|RELEASED'],true)&&($paid===null||$last===null))$this->fail();
        $released=str_contains($combination,'|RELEASED');if(!$released){if(($row['release_id']??'')!==''||($row['release_fingerprint']??'')!==''||($row['released_at']??'')!=='')$this->fail();}elseif(!is_string($row['release_id']??null)||preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di',$row['release_id'])!==1||!is_string($row['release_fingerprint']??null)||preg_match('/^[0-9a-f]{64}$/D',$row['release_fingerprint'])!==1||$this->time($row['released_at']??null)!==$row['released_at'])$this->fail();
        return ['revision'=>$revision,'paid_at'=>$paid,'payment_last_event_at'=>$last,'combination'=>$combination];
    }
    /** @return array<string,array<string,mixed>> indexed by transaction id */
    public function payments(array $rows):array
    { $ids=[];$transactions=[];foreach($rows as $row){$id=$this->positive($row['payment_attempt_id']??null);$this->positive($row['order_id']??null);$transaction=$this->storedText($row['wompi_transaction_id']??null,1,200);if(isset($ids[$id])||isset($transactions[$transaction]))$this->fail();if(!in_array($row['status']??null,self::STATUSES,true)||$this->storedText($row['payment_method']??null,1,100)===''||$this->positive($row['amount_in_cents']??null)<1||($row['currency']??null)!=='COP')$this->fail();$this->time($row['created_at']??null);$this->time($row['updated_at']??null);$ids[$id]=true;$transactions[$transaction]=$row;}return $transactions; }
    public function time(mixed $value):string{try{return ($this->timestamps??new CheckoutUtcTimestamp)->parse(is_string($value)?$value:'')['iso'];}catch(\Throwable){$this->fail();}}
    private function optionalTime(mixed $value):?string{if($value===''||$value===null)return null;return $this->time($value);}
    private function positive(mixed $value):int{if(!is_int($value)||$value<1||$value>2147483647)$this->fail();return $value;}
    private function nonNegative(mixed $value):int{if(!is_int($value)||$value<0||$value>2147483647)$this->fail();return $value;}
    private function storedText(mixed $value,int $min,int $max):string{if(!is_string($value)||$value!==$this->jsTrim($value)||$this->jsLength($value)<$min||$this->jsLength($value)>$max)$this->fail();return $value;}
    private function jsTrim(string $value):string{return preg_replace('/^[\\s\\x{FEFF}\\x{00A0}]+|[\\s\\x{FEFF}\\x{00A0}]+$/u','',$value)??$value;}
    private function jsLength(string $value):int{return intdiv(strlen(mb_convert_encoding($value,'UTF-16LE','UTF-8')),2);}
    private function fail():never{throw new CheckoutPaymentEventException('CONSISTENCY_UNCERTAIN');}
}
