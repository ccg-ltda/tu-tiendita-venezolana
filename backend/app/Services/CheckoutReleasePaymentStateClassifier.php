<?php
namespace App\Services;
use App\Exceptions\CheckoutConsistencyException;
final class CheckoutReleasePaymentStateClassifier
{
 public function __construct(private readonly CheckoutUtcTimestamp $timestamps) {}
 /** @param array<string,mixed> $actual @param array<string,mixed> $plan */
 public function classify(array $actual,array $plan):string
 {foreach(['created_at','updated_at_before','updated_at_after'] as $key)$this->timestamps->parse($plan[$key]??throw new CheckoutConsistencyException);$invariant=$actual['payment_attempt_id']===$plan['payment_attempt_id']&&$actual['order_id']===$plan['order_id']&&$actual['wompi_transaction_id']===$plan['wompi_transaction_id']&&$actual['payment_method']===$plan['payment_method']&&$actual['amount_in_cents']===$plan['amount_in_cents']&&$actual['currency']===$plan['currency']&&$actual['created_at']===$plan['created_at'];if($invariant&&$actual['status']===$plan['status_before']&&$actual['updated_at']===$plan['updated_at_before'])return 'BEFORE';if($invariant&&$actual['status']===$plan['status_after']&&$actual['updated_at']===$plan['updated_at_after'])return 'AFTER';throw new CheckoutConsistencyException;}
}
