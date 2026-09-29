<?php

namespace Tests\Unit\Services;

use App\Services\CheckoutReleaseFingerprint;
use Tests\TestCase;

final class CheckoutReleaseFingerprintTest extends TestCase
{
    /**
     * Fixed vector also calculated with Node's JSON.stringify + SHA-256,
     * matching Apps Script calcularFingerprintLiberacion_ canonical ordering.
     */
    public function test_fixed_apps_script_canonicalization_vector(): void
    {
        $hash=(new CheckoutReleaseFingerprint)->calculate(17,'TTV-20260925-H-35AAA0FF',4,
            [['product_id'=>8,'quantity'=>1],['product_id'=>7,'quantity'=>2],['product_id'=>8,'quantity'=>3]],
            [['wompi_transaction_id'=>'tx-b','status'=>'VOIDED','checked_at'=>'2026-09-25T12:00:00.000Z'],['wompi_transaction_id'=>'tx-a','status'=>'DECLINED','checked_at'=>'2026-09-25T12:01:00.000Z']]);
        $this->assertSame('9bb4d4672d1c364983abaae378670ea7154b05a44e593090f3b4bd62436906c3',$hash);
    }
}
