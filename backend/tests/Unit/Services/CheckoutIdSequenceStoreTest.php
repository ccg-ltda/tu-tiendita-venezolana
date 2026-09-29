<?php

namespace Tests\Unit\Services;

use App\Exceptions\CheckoutConsistencyException;
use App\Services\CheckoutIdSequenceStore;
use Tests\TestCase;

class CheckoutIdSequenceStoreTest extends TestCase
{
    private string $path;
    protected function setUp(): void { parent::setUp(); $this->path=sys_get_temp_dir().DIRECTORY_SEPARATOR.'checkout-sequences-'.bin2hex(random_bytes(6)).'.json'; }
    public function test_it_initializes_from_maxima_never_moves_backward_and_reserves_consecutive_ids(): void
    {
        $store=new CheckoutIdSequenceStore($this->path);
        $this->assertSame(['next_order_id'=>18,'next_order_item_id'=>31,'next_payment_attempt_id'=>5],$store->initializeFromMaxima(17,30,4));
        $this->assertSame(18,$store->reserveOrderId());
        $this->assertSame(31,$store->reserveOrderItemIds(3));
        $this->assertSame(5,$store->reservePaymentAttemptId());
        $this->assertSame(['next_order_id'=>19,'next_order_item_id'=>34,'next_payment_attempt_id'=>6],$store->initializeFromMaxima(1,1,1));
    }
    public function test_multiple_store_instances_do_not_duplicate_reserved_ids(): void
    {
        $first=new CheckoutIdSequenceStore($this->path);$second=new CheckoutIdSequenceStore($this->path);
        $reserved=[];for($i=0;$i<10;$i++)$reserved[]=$i%2===0?$first->reserveOrderId():$second->reserveOrderId();
        $this->assertSame(range(1,10),$reserved);
    }
    public function test_it_fails_closed_for_corrupt_sequence_file(): void
    {
        file_put_contents($this->path,'not json');
        $this->expectException(CheckoutConsistencyException::class);
        (new CheckoutIdSequenceStore($this->path))->reserveOrderId();
    }
}
