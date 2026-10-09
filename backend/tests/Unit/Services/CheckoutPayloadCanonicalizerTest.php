<?php

namespace Tests\Unit\Services;

use App\Services\CheckoutPayloadCanonicalizer;
use Tests\TestCase;

class CheckoutPayloadCanonicalizerTest extends TestCase
{
    public function test_item_order_and_duplicates_produce_the_same_hash(): void
    {
        $canonicalizer=new CheckoutPayloadCanonicalizer;
        $first=$canonicalizer->canonicalize($this->customer(),[['id'=>9,'qty'=>1],['id'=>3,'qty'=>2],['id'=>9,'qty'=>2]]);
        $second=$canonicalizer->canonicalize($this->customer(),[['id'=>'9','qty'=>'3'],['id'=>'3','qty'=>'2']]);
        $this->assertSame([['product_id'=>3,'quantity'=>2],['product_id'=>9,'quantity'=>3]],$first['items']);
        $this->assertSame($first['payload_hash'],$second['payload_hash']);
    }
    public function test_quantity_or_customer_changes_the_hash_and_same_payload_is_deterministic(): void
    {
        $canonicalizer=new CheckoutPayloadCanonicalizer;
        $base=$canonicalizer->canonicalize($this->customer(),[['id'=>193,'qty'=>1]]);
        $this->assertSame($base,$canonicalizer->canonicalize($this->customer(),[['id'=>193,'qty'=>1]]));
        $this->assertNotSame($base['payload_hash'],$canonicalizer->canonicalize($this->customer(),[['id'=>193,'qty'=>2]])['payload_hash']);
        $customer=$this->customer();$customer['city']='Medellin';
        $this->assertNotSame($base['payload_hash'],$canonicalizer->canonicalize($customer,[['id'=>193,'qty'=>1]])['payload_hash']);
    }
    public function test_coupon_code_is_normalized_and_changes_the_hash(): void
    {
        $canonicalizer=new CheckoutPayloadCanonicalizer;
        $without=$canonicalizer->canonicalize($this->customer(),[['id'=>193,'qty'=>1]]);
        $with=$canonicalizer->canonicalize($this->customer(),[['id'=>193,'qty'=>1]],' bienvenida10 ');
        $this->assertSame('BIENVENIDA10',$with['coupon_code']);
        $this->assertNotSame($without['payload_hash'],$with['payload_hash']);
        $this->assertSame($with['payload_hash'],$canonicalizer->canonicalize($this->customer(),[['id'=>193,'qty'=>1]],'BIENVENIDA10')['payload_hash']);
    }
    public function test_it_matches_the_current_prepare_hash_for_normalized_payload(): void
    {
        $result=(new CheckoutPayloadCanonicalizer)->canonicalize($this->customer(),[['id'=>193,'qty'=>1]]);
        $this->assertNull($result['coupon_code']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/',$result['payload_hash']);
    }
    private function customer(): array
    { return ['name'=>'Prueba Release','email'=>'release@test.local','phone'=>'+573000000000','document'=>'1000000000','address'=>'Dirección prueba','extra'=>null,'city'=>'Bogotá','region'=>'Bogotá D.C.','postal'=>'110111']; }
}
