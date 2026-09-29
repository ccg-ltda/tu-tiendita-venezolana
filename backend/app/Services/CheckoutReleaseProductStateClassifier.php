<?php
namespace App\Services;
use App\Exceptions\CheckoutConsistencyException;
final class CheckoutReleaseProductStateClassifier
{
 public function __construct(private readonly CheckoutUtcTimestamp $timestamps) {}
 /** @param array<string,mixed> $actual @param array<string,mixed> $plan */
 public function classify(array $actual,array $plan):string
 {
  /* Apps Script persists both timestamps but its recovery classifier only
     compares identity, inventory and revision. Timestamp equality belongs to
     verificarLiberacionFinalDatos_, after all rows reach AFTER. */
  if(array_key_exists('updated_at_before',$plan)&&$plan['updated_at_before']!==null)$this->timestamps->parse(is_string($plan['updated_at_before'])?$plan['updated_at_before']:throw new CheckoutConsistencyException);
  $this->timestamps->parse(is_string($plan['updated_at_after']??null)?$plan['updated_at_after']:throw new CheckoutConsistencyException);
  $identity=($actual['product_id']??null)===$plan['product_id']&&($actual['sheet_row']??null)===$plan['row_number'];
  if($identity&&($actual['inventory']??null)===$plan['inventory_before']&&($actual['revision']??null)===$plan['revision_before'])return 'BEFORE';
  if($identity&&($actual['inventory']??null)===$plan['inventory_after']&&($actual['revision']??null)===$plan['revision_after'])return 'AFTER';
  throw new CheckoutConsistencyException;
 }
}
