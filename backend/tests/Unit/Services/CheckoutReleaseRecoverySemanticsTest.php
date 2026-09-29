<?php

namespace Tests\Unit\Services;

use App\Exceptions\CheckoutConsistencyException;
use App\Services\CheckoutReleaseDurableMarkerVerifier;
use App\Services\CheckoutReleaseFinalOrderVerifier;
use App\Services\CheckoutReleaseProductStateClassifier;
use App\Services\CheckoutUtcTimestamp;
use Tests\TestCase;

final class CheckoutReleaseRecoverySemanticsTest extends TestCase
{
    private const BEFORE='2026-09-25T12:00:00.100Z';
    private const AFTER='2026-09-25T12:10:00.100Z';
    private function product():array{return ['product_id'=>7,'row_number'=>9,'inventory_before'=>1,'inventory_after'=>3,'revision_before'=>4,'revision_after'=>5,'updated_at_before'=>self::BEFORE,'updated_at_after'=>self::AFTER];}
    private function order():array{return ['order_id'=>17,'reference'=>'TTV-TEST','status'=>'PENDING','payment_status'=>'PENDING','reservation_status'=>'RELEASED','release_id'=>'4f4ac525-91d1-4d43-8b91-2a0cb8472999','release_fingerprint'=>str_repeat('a',64),'released_at'=>self::AFTER,'updated_at'=>self::AFTER,'paid_at'=>null,'payment_last_event_at'=>null,'revision'=>2,'total_cop'=>100];}
    private function expected():array{return ['order_id'=>17,'reference'=>'TTV-TEST','status_after'=>'PENDING','payment_status_after'=>'PENDING','reservation_status_after'=>'RELEASED','revision_after'=>2,'release_id'=>'4f4ac525-91d1-4d43-8b91-2a0cb8472999','release_fingerprint'=>str_repeat('a',64),'released_at_after'=>self::AFTER,'updated_at_after'=>self::AFTER,'paid_at_after'=>null,'payment_last_event_at_after'=>null,'total_cop'=>100];}
    public function test_product_before_ignores_saved_updated_at_as_apps_script_does():void
    {$plan=$this->product();$actual=['product_id'=>7,'sheet_row'=>9,'inventory'=>1,'revision'=>4,'updated_at'=>'2026-09-25T12:03:00.100Z'];$this->assertSame('BEFORE',(new CheckoutReleaseProductStateClassifier(new CheckoutUtcTimestamp))->classify($actual,$plan));}
    public function test_product_after_classification_ignores_timestamp_but_final_check_must_not():void
    {$plan=$this->product();$actual=['product_id'=>7,'sheet_row'=>9,'inventory'=>3,'revision'=>5,'updated_at'=>self::BEFORE];$this->assertSame('AFTER',(new CheckoutReleaseProductStateClassifier(new CheckoutUtcTimestamp))->classify($actual,$plan));$this->assertNotSame($plan['updated_at_after'],$actual['updated_at']);}
    public function test_durable_replay_marker_and_final_verification_are_distinct():void
    {$order=$this->order();$expected=$this->expected();$durable=new CheckoutReleaseDurableMarkerVerifier(new CheckoutUtcTimestamp);$final=new CheckoutReleaseFinalOrderVerifier(new CheckoutUtcTimestamp);$this->assertTrue($durable->matches($order,$expected));$order['updated_at']=self::BEFORE;$this->assertTrue($durable->matches($order,$expected));$this->assertFalse($final->matches($order,$expected));$order['updated_at']=self::AFTER;$this->assertTrue($final->matches($order,$expected));}
    public function test_final_verification_requires_nullable_payment_timestamps_to_match():void
    {$order=$this->order();$expected=$this->expected();$expected['paid_at_after']=self::BEFORE;$this->assertFalse((new CheckoutReleaseFinalOrderVerifier(new CheckoutUtcTimestamp))->matches($order,$expected));$order['paid_at']=self::BEFORE;$this->assertTrue((new CheckoutReleaseFinalOrderVerifier(new CheckoutUtcTimestamp))->matches($order,$expected));}
    public function test_classifier_rejects_noncanonical_after_timestamp_in_plan():void
    {$plan=$this->product();$plan['updated_at_after']='2026-09-25T12:10:00Z';$this->expectException(CheckoutConsistencyException::class);(new CheckoutReleaseProductStateClassifier(new CheckoutUtcTimestamp))->classify(['product_id'=>7,'sheet_row'=>9,'inventory'=>3,'revision'=>5,'updated_at'=>self::AFTER],$plan);}
}
