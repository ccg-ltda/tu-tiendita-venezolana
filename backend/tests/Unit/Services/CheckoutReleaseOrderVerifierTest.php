<?php

namespace Tests\Unit\Services;

use App\Services\CheckoutReleaseDurableMarkerVerifier;
use App\Services\CheckoutReleaseFinalOrderVerifier;
use App\Services\CheckoutReleaseOrderBeforeVerifier;
use App\Services\CheckoutUtcTimestamp;
use Tests\TestCase;

final class CheckoutReleaseOrderVerifierTest extends TestCase
{
    private const T='2026-09-25T12:00:00.111Z';
    private function expected():array{return ['order_id'=>17,'reference'=>'TTV-TEST','status_after'=>'PENDING','payment_status_after'=>'PENDING','reservation_status_after'=>'RELEASED','revision_after'=>2,'release_id'=>'4f4ac525-91d1-4d43-8b91-2a0cb8472999','release_fingerprint'=>str_repeat('a',64),'released_at_after'=>self::T,'updated_at_after'=>self::T,'paid_at_after'=>null,'payment_last_event_at_after'=>null,'total_cop'=>100];}
    private function order():array{return ['order_id'=>17,'reference'=>'TTV-TEST','status'=>'PENDING','payment_status'=>'PENDING','reservation_status'=>'RELEASED','release_id'=>'4f4ac525-91d1-4d43-8b91-2a0cb8472999','release_fingerprint'=>str_repeat('a',64),'released_at'=>self::T,'updated_at'=>self::T,'paid_at'=>null,'payment_last_event_at'=>null,'revision'=>2,'total_cop'=>100];}
    public function test_durable_marker_rejects_each_of_its_authoritative_fields():void{$v=new CheckoutReleaseDurableMarkerVerifier(new CheckoutUtcTimestamp);foreach(['order_id'=>18,'reference'=>'OTHER','status'=>'PROCESSING','payment_status'=>'APPROVED','reservation_status'=>'ACTIVE','release_id'=>'4f4ac525-91d1-4d43-8b91-2a0cb8472998','release_fingerprint'=>str_repeat('b',64),'revision'=>3,'released_at'=>'2026-09-25T12:00:00.112Z'] as $field=>$value){$order=$this->order();$order[$field]=$value;$this->assertFalse($v->matches($order,$this->expected()),$field);}}
    public function test_final_order_verifier_rejects_every_final_field_difference():void{$v=new CheckoutReleaseFinalOrderVerifier(new CheckoutUtcTimestamp);foreach(['order_id'=>18,'reference'=>'OTHER','status'=>'PROCESSING','payment_status'=>'APPROVED','reservation_status'=>'ACTIVE','release_id'=>'4f4ac525-91d1-4d43-8b91-2a0cb8472998','release_fingerprint'=>str_repeat('b',64),'released_at'=>'2026-09-25T12:00:00.112Z','updated_at'=>'2026-09-25T12:00:00.112Z','revision'=>3,'paid_at'=>self::T,'payment_last_event_at'=>self::T,'total_cop'=>101] as $field=>$value){$order=$this->order();$order[$field]=$value;$this->assertFalse($v->matches($order,$this->expected()),$field);}}
    public function test_final_order_verifier_accepts_exact_optional_payment_timestamps():void{$expected=$this->expected();$expected['paid_at_after']='2026-09-25T12:00:00.110Z';$expected['payment_last_event_at_after']=self::T;$order=$this->order();$order['paid_at']='2026-09-25T12:00:00.110Z';$order['payment_last_event_at']=self::T;$this->assertTrue((new CheckoutReleaseFinalOrderVerifier(new CheckoutUtcTimestamp))->matches($order,$expected));}
    public function test_before_verifier_accepts_empty_release_fields_but_requires_active_order_invariants():void{$order=['order_id'=>17,'reference'=>'TTV-TEST','status'=>'PENDING','payment_status'=>'PENDING','reservation_status'=>'ACTIVE','revision'=>1,'created_at'=>self::T,'updated_at'=>self::T,'reservation_expires_at'=>'2026-09-25T12:10:00.111Z','paid_at'=>'','payment_last_event_at'=>'','released_at'=>'','release_id'=>'','release_fingerprint'=>'','total_cop'=>100];$expected=$this->expected()+['revision_before'=>1];$this->assertTrue((new CheckoutReleaseOrderBeforeVerifier(new CheckoutUtcTimestamp))->matches($order,$expected));$order['paid_at']=self::T;$this->assertFalse((new CheckoutReleaseOrderBeforeVerifier(new CheckoutUtcTimestamp))->matches($order,$expected));}
}
