<?php

namespace App\Services;

use App\Exceptions\CheckoutConsistencyException;

/** Exact counterpart of Apps Script verificarPedidoLiberacionFinal_. */
final class CheckoutReleaseFinalOrderVerifier
{
    public function __construct(private readonly CheckoutUtcTimestamp $timestamps) {}

    /** @param array<string,mixed> $order @param array<string,mixed> $expected */
    public function matches(array $order,array $expected): bool
    {
        foreach(['order_id','reference','status_after','payment_status_after','reservation_status_after','revision_after','release_id','release_fingerprint','released_at_after','updated_at_after','paid_at_after','payment_last_event_at_after','total_cop'] as $key) {
            if(!array_key_exists($key,$expected)) throw new CheckoutConsistencyException;
        }
        $this->timestamps->parse($this->string($expected['released_at_after']));
        $this->timestamps->parse($this->string($expected['updated_at_after']));
        foreach(['paid_at_after','payment_last_event_at_after'] as $key)if($expected[$key]!==null)$this->timestamps->parse($this->string($expected[$key]));
        foreach(['released_at','updated_at'] as $key)$this->timestamps->parse($this->string($order[$key]??null));
        foreach(['paid_at','payment_last_event_at'] as $key)if(($order[$key]??null)!==null&&($order[$key]??null)!=='')$this->timestamps->parse($this->string($order[$key]));
        return $order['order_id']===$expected['order_id']
            &&$order['reference']===$expected['reference']
            &&$order['status']===$expected['status_after']
            &&$order['payment_status']===$expected['payment_status_after']
            &&$order['reservation_status']===$expected['reservation_status_after']
            &&$order['release_id']===$expected['release_id']
            &&$order['release_fingerprint']===$expected['release_fingerprint']
            &&$order['released_at']===$expected['released_at_after']
            &&$order['updated_at']===$expected['updated_at_after']
            &&$this->nullableEquals($order['paid_at']??null,$expected['paid_at_after'])
            &&$this->nullableEquals($order['payment_last_event_at']??null,$expected['payment_last_event_at_after'])
            &&$order['revision']===$expected['revision_after']
            &&$order['total_cop']===$expected['total_cop'];
    }

    private function nullableEquals(mixed $actual,mixed $expected): bool
    { return ($actual===null||$actual==='') ? $expected===null : $actual===$expected; }
    private function string(mixed $value): string
    { if(!is_string($value))throw new CheckoutConsistencyException;return $value; }
}
