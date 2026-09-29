<?php

namespace App\Services;

use App\Exceptions\CheckoutConsistencyException;

/** PHP equivalent of calcularFingerprintLiberacion_ in Apps Script. */
final class CheckoutReleaseFingerprint
{
    /** @param list<array{product_id:int,quantity:int}> $items @param list<array{wompi_transaction_id:string,status:string,checked_at:string}> $verified */
    public function calculate(int $orderId,string $reference,int $expectedRevision,array $items,array $verified): string
    {
        $grouped=[];foreach($items as $item){if($item['product_id']<1||$item['quantity']<1)throw new CheckoutConsistencyException;$grouped[$item['product_id']]=($grouped[$item['product_id']]??0)+$item['quantity'];}
        ksort($grouped,SORT_NUMERIC);usort($verified,fn($a,$b)=>$a['wompi_transaction_id']<=>$b['wompi_transaction_id']);
        $canonical=['order_id'=>$orderId,'reference'=>$reference,'expected_revision'=>$expectedRevision,'items'=>array_map(fn($id)=>['product_id'=>(int)$id,'quantity'=>$grouped[$id]],array_keys($grouped)),'verified_final_attempts'=>array_map(fn($v)=>['wompi_transaction_id'=>$v['wompi_transaction_id'],'status'=>$v['status']],$verified)];
        return hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR));
    }
}
