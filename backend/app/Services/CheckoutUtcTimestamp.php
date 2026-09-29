<?php

namespace App\Services;

use App\Exceptions\CheckoutConsistencyException;

/** Strict counterpart of fechaUtcEventoPago_: YYYY-MM-DDTHH:mm:ss.sssZ only. */
final class CheckoutUtcTimestamp
{
    /** @return array{iso:string,epoch_ms:int} */
    public function parse(string $value): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D',$value)!==1) throw new CheckoutConsistencyException;
        try {$date=new \DateTimeImmutable($value);$iso=$date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.v\\Z');}catch(\Throwable){throw new CheckoutConsistencyException;}
        if($iso!==$value)throw new CheckoutConsistencyException;
        return ['iso'=>$iso,'epoch_ms'=>(int)$date->format('U')*1000+(int)$date->format('v')];
    }
    public function isAtOrAfter(string $left,string $right): bool {return $this->parse($left)['epoch_ms']>=$this->parse($right)['epoch_ms'];}
}
