<?php

namespace App\Services;

use App\Exceptions\CheckoutConsistencyException;

/** Literal counterpart of the ACTIVE/PENDING branch in recuperarPlanLiberacion_. */
final class CheckoutReleaseOrderBeforeVerifier
{
    public function __construct(private readonly CheckoutUtcTimestamp $timestamps) {}

    /** @param array<string,mixed> $order @param array<string,mixed> $expected */
    public function matches(array $order,array $expected): bool
    {
        foreach(['order_id','reference','revision_before'] as $key)if(!array_key_exists($key,$expected))throw new CheckoutConsistencyException;
        try {
            foreach(['created_at','updated_at','reservation_expires_at'] as $key)$this->timestamps->parse($this->string($order[$key]??null));
            $last=$order['payment_last_event_at']??null;if($last!==null&&$last!=='')$this->timestamps->parse($this->string($last));
        } catch(CheckoutConsistencyException) { return false; }
        return $order['order_id']===$expected['order_id']
            &&$order['reference']===$expected['reference']
            &&$order['status']==='PENDING'
            &&$order['payment_status']==='PENDING'
            &&$order['reservation_status']==='ACTIVE'
            &&$order['revision']===$expected['revision_before']
            &&(($order['paid_at']??null)===null||($order['paid_at']??null)==='')
            &&is_int($order['total_cop'])&&$order['total_cop']>=0;
    }
    private function string(mixed $value): string {if(!is_string($value))throw new CheckoutConsistencyException;return $value;}
}
