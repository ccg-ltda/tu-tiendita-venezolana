<?php
namespace App\Services;
use App\Exceptions\CheckoutConsistencyException;
final class CheckoutReleaseDurableMarkerVerifier
{
    public function __construct(private readonly CheckoutUtcTimestamp $timestamps) {}
    /** @param array<string,mixed> $order @param array<string,mixed> $expected */
    public function matches(array $order,array $expected): bool
    {
        /* Exact counterpart of pedidoCoincideLiberacionDurable_: this is the
           lightweight journal replay marker, not final-order verification. */
        foreach(['order_id','reference','status_after','payment_status_after','reservation_status_after','revision_after','release_id','release_fingerprint','released_at_after'] as $key)if(!array_key_exists($key,$expected))throw new CheckoutConsistencyException;
        try{$this->timestamps->parse($expected['released_at_after']);}catch(\Throwable){throw new CheckoutConsistencyException;}
        if(!is_string($expected['release_id'])||preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di',$expected['release_id'])!==1||!is_string($expected['release_fingerprint'])||preg_match('/^[0-9a-f]{64}$/D',$expected['release_fingerprint'])!==1)throw new CheckoutConsistencyException;
        foreach(['released_at','updated_at'] as $key)$this->timestamps->parse($order[$key]??throw new CheckoutConsistencyException);
        return $order['order_id']===$expected['order_id']&&$order['reference']===$expected['reference']&&$order['status']===$expected['status_after']&&$order['payment_status']===$expected['payment_status_after']&&$order['reservation_status']===$expected['reservation_status_after']&&$order['release_id']===$expected['release_id']&&$order['release_fingerprint']===$expected['release_fingerprint']&&$order['released_at']===$expected['released_at_after']&&$order['revision']===$expected['revision_after'];
    }
}
