<?php

namespace Tests\Unit\Services;

use App\Exceptions\CheckoutConsistencyException;
use App\Services\CheckoutJournalStore;
use Tests\TestCase;

class CheckoutJournalStoreTest extends TestCase
{
    private string $directory;
    private string $key;
    protected function setUp(): void { parent::setUp(); $this->directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'checkout-journal-'.bin2hex(random_bytes(6)); $this->key=hash('sha256','key'); }
    public function test_it_creates_loads_advances_finalizes_and_deletes_atomically(): void
    {
        $store=new CheckoutJournalStore($this->directory);
        $created=$store->createPrepared($this->key,hash('sha256','payload'),17,30,1,'TTV-20260925-H-35AAA0FF','2026-09-25T12:10:00.000Z',$this->plan(),$this->items(),'2026-09-25T12:00:00.000Z');
        $this->assertSame('PREPARED',$created['stage']);
        $this->assertSame($created,$store->load($this->key));
        $store->advanceStage($this->key,'ORDER_WRITTEN');$store->advanceStage($this->key,'ITEMS_WRITTEN');$store->advanceStage($this->key,'PRODUCTS_WRITTEN');
        $this->assertSame('FINALIZED',$store->markFinalized($this->key)['stage']);
        $store->delete($this->key);
        $this->assertNull($store->load($this->key));
    }
    public function test_it_rejects_invalid_stage_transition_and_corrupt_journal(): void
    {
        $store=new CheckoutJournalStore($this->directory);
        $store->createPrepared($this->key,hash('sha256','payload'),17,30,1,'TTV-20260925-H-35AAA0FF','2026-09-25T12:10:00.000Z',$this->plan(),$this->items());
        try{$store->advanceStage($this->key,'PRODUCTS_WRITTEN');$this->fail('Expected invalid transition.');}catch(CheckoutConsistencyException){}
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.$this->key.'.json','{broken');
        $this->expectException(CheckoutConsistencyException::class);
        $store->load($this->key);
    }
    private function plan(): array { return [['product_id'=>7,'row_number'=>4,'inventory_before'=>5,'inventory_after'=>4,'revision_before'=>1,'revision_after'=>2]]; }
    private function items(): array { return [[30,17,7,'Harina',5000,1,'2026-09-25T12:00:00.000Z']]; }
}
