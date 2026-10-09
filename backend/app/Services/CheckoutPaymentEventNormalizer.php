<?php

namespace App\Services;

use App\Exceptions\CheckoutPaymentEventException;

/** Literal counterpart of normalizarEventoPago_. */
final class CheckoutPaymentEventNormalizer
{
    private const STATUSES=['PENDING','APPROVED','DECLINED','VOIDED','ERROR'];
    /** @return array{id:string,reference:string,status:string,payment_method:string,amount_in_cents:int,currency:string,event_occurred_at:string,event_occurred_ms:int} */
    public function normalize(mixed $request, ?\DateTimeInterface $now=null): array
    {
        if(!is_array($request)||!isset($request['transaction'])||!is_array($request['transaction']))$this->fail('INVALID_REQUEST');
        $t=$request['transaction'];$id=$this->text($t['id']??null,1,200);$reference=$this->text($t['reference']??null,1,500);
        if(!in_array($t['status']??null,self::STATUSES,true))$this->fail('INVALID_REQUEST');$method=$this->text($t['payment_method']??null,1,100);
        $amount=$t['amount_in_cents']??null;if(!is_int($amount)||$amount<1||$amount>9007199254740991)$this->fail('INVALID_REQUEST');
        if(!is_string($t['currency']??null)||$t['currency']!=='COP')$this->fail('CURRENCY_MISMATCH');
        try{$date=new \DateTimeImmutable((string) ($t['event_occurred_at']??''));$date=$date->setTimezone(new \DateTimeZone('UTC'));$occurred=['iso'=>$date->format('Y-m-d\\TH:i:s.v\\Z'),'epoch_ms'=>((int)$date->format('U'))*1000+(int)$date->format('v')];}catch(\Throwable){$this->fail('INVALID_REQUEST');}
        $current=$now??now('UTC');$future=(int)$current->format('U')*1000+(int)$current->format('v')+600000;
        if($occurred['epoch_ms']>$future)$this->fail('INVALID_REQUEST');
        return ['id'=>$id,'reference'=>$reference,'status'=>$t['status'],'payment_method'=>$method,'amount_in_cents'=>$amount,'currency'=>'COP','event_occurred_at'=>$occurred['iso'],'event_occurred_ms'=>$occurred['epoch_ms']];
    }
    private function text(mixed $value,int $min,int $max):string{if(!is_string($value))$this->fail('INVALID_REQUEST');$value=$this->jsTrim($value);$length=$this->jsLength($value);if($length<$min||$length>$max)$this->fail('INVALID_REQUEST');return $value;}
    private function jsTrim(string $value):string{return preg_replace('/^[\\s\\x{FEFF}\\x{00A0}]+|[\\s\\x{FEFF}\\x{00A0}]+$/u','',$value)??$value;}
    private function jsLength(string $value):int{return intdiv(strlen(mb_convert_encoding($value,'UTF-16LE','UTF-8')),2);}
    private function fail(string $code):never{throw new CheckoutPaymentEventException($code);}
}
