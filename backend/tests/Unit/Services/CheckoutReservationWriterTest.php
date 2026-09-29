<?php

namespace Tests\Unit\Services;

use App\Contracts\GoogleSheetsValuesClient;
use App\Data\CheckoutReservationPlan;
use App\Exceptions\CheckoutConsistencyException;
use App\Repositories\CheckoutSheetsRepository;
use App\Services\CheckoutJournalStore;
use App\Services\CheckoutLock;
use App\Services\CheckoutRecoveryService;
use App\Services\CheckoutReservationWriter;
use Tests\TestCase;

class CheckoutReservationWriterTest extends TestCase
{
    public function test_it_writes_technical_order_items_inventory_and_final_order_then_removes_journal(): void
    {
        [$writer,$journal,$repository]=$this->services();$plan=$this->plan();$writer->execute($plan);
        $order=$repository->readOrderById(1);
        $this->assertSame('PENDING',$order['status']);$this->assertSame('ACTIVE',$order['reservation_status']);$this->assertSame(1,$order['revision']);
        $this->assertCount(1,$repository->readOrderItemsByOrderId(1));$this->assertSame(3,$repository->findProductById(7)['inventory']);$this->assertFalse($journal->exists(hash('sha256',$plan->idempotencyKey)));
    }
    public function test_recovery_after_technical_write_finishes_once_without_double_discount(): void
    {
        [$writer,$journal,$repository,$recovery,$client]=$this->services();$plan=$this->plan();$client->failAfterWrite=1;
        try{$writer->execute($plan);$this->fail('Expected induced failure.');}catch(\RuntimeException){}
        $client->failAfterWrite=null;$first=$recovery->recover($plan->idempotencyKey);$second=$recovery->recover($plan->idempotencyKey);
        $this->assertSame('PENDING',$first['status']);$this->assertNull($second);$this->assertSame(3,$repository->findProductById(7)['inventory']);$this->assertCount(1,$repository->readOrderItemsByOrderId(1));$this->assertFalse($journal->exists(hash('sha256',$plan->idempotencyKey)));
    }
    public function test_mixed_inventory_recovery_fails_closed_and_keeps_journal(): void
    {
        [$writer,$journal,$repository,$recovery,$client]=$this->services(twoProducts:true);$plan=$this->plan(twoProducts:true);$client->failAfterWrite=3;
        try{$writer->execute($plan);$this->fail('Expected induced failure.');}catch(\RuntimeException){}
        $client->failAfterWrite=null;
        $this->expectException(CheckoutConsistencyException::class);
        try{$recovery->recover($plan->idempotencyKey);}finally{$this->assertTrue($journal->exists(hash('sha256',$plan->idempotencyKey)));}
    }
    private function services(bool $twoProducts=false): array
    {
        $client=new CheckoutMutableFake($twoProducts);$repository=new CheckoutSheetsRepository($client);$directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'checkout-writer-'.bin2hex(random_bytes(6));$journal=new CheckoutJournalStore($directory);$lock=new CheckoutLock;$writer=new CheckoutReservationWriter($lock,$journal,$repository);return [$writer,$journal,$repository,new CheckoutRecoveryService($lock,$journal,$repository),$client];
    }
    private function plan(bool $twoProducts=false): CheckoutReservationPlan
    {
        $key='11111111-1111-4111-8111-111111111111';$items=[['product_id'=>7,'quantity'=>2]];$inventory=[['product_id'=>7,'row_number'=>2,'inventory_before'=>5,'inventory_after'=>3,'revision_before'=>1,'revision_after'=>2]];$rows=[[1,1,7,'Harina',5000,2,'2026-09-25T12:00:00.000Z']];
        if($twoProducts){$items[]=['product_id'=>8,'quantity'=>1];$inventory[]=['product_id'=>8,'row_number'=>3,'inventory_before'=>2,'inventory_after'=>1,'revision_before'=>1,'revision_after'=>2];$rows[]=[2,1,8,'Aceite',3000,1,'2026-09-25T12:00:00.000Z'];}
        $total=$twoProducts?13000:10000;$order=[1,'TTV-20260925-1-AAAAAAAA','PENDING','PENDING','ACTIVE','2026-09-25T12:10:00.000Z','','',$key,hash('sha256','payload'),'','','','Cliente','cliente@example.test','+573000000000','1000000000','Calle 1','','Bogota','Bogota','',$total,'2026-09-25T12:00:00.000Z','2026-09-25T12:00:00.000Z',1];
        return new CheckoutReservationPlan(false,['name'=>'Cliente','email'=>'cliente@example.test','phone'=>'+573000000000','document'=>'1000000000','address'=>'Calle 1','extra'=>null,'city'=>'Bogota','region'=>'Bogota','postal'=>null],$items,$key,hash('sha256','payload'),1,1,'TTV-20260925-1-AAAAAAAA',$total,'2026-09-25T12:10:00.000Z','2026-09-25T12:00:00.000Z',$inventory,2,$order,$rows);
    }
}

class CheckoutMutableFake implements GoogleSheetsValuesClient
{
    public ?int $failAfterWrite=null;private int $writes=0;private array $tables;
    public function __construct(bool $twoProducts){$this->tables=['Productos'=>[['product_id','category','subcategory','name','presentation','price_cop','inventory','active','image_path','legacy_img','created_at','updated_at','revision'],[7,'D','H','Harina','1 kg',5000,5,true,'','','2026-09-25T12:00:00.000Z','2026-09-25T12:00:00.000Z',1]],'Pedidos'=>[['order_id','reference','status','payment_status','reservation_status','reservation_expires_at','paid_at','payment_last_event_at','checkout_idempotency_key','checkout_payload_hash','release_id','release_fingerprint','released_at','customer_name','customer_email','customer_phone','customer_document','address','extra','city','region','postal','total_cop','created_at','updated_at','revision']],'PedidoItems'=>[['order_item_id','order_id','product_id','product_name','unit_price_cop','quantity','created_at']],'Pagos'=>[['payment_attempt_id','order_id','wompi_transaction_id','status','payment_method','amount_in_cents','currency','created_at','updated_at']]];if($twoProducts)$this->tables['Productos'][]=[8,'D','A','Aceite','1 L',3000,2,true,'','','2026-09-25T12:00:00.000Z','2026-09-25T12:00:00.000Z',1];}
    public function getValues(string $range): array{return [];}
    public function batchGetValues(array $ranges): array{return array_map(function(string $range):array{$sheet=explode('!',$range,2)[0];return str_ends_with($range,'1:1')?[array_values($this->tables[$sheet][0])]:$this->tables[$sheet];},$ranges);}
    public function updateValues(string $range,array $values): array{$sheet=str_starts_with($range,'Pedidos!')?'Pedidos':'Productos';preg_match('/![A-Z]+(\d+)/',$range,$m);$this->tables[$sheet][(int)$m[1]-1]=$values[0];$this->write();return [];}
    public function appendValues(string $range,array $values): array{foreach($values as $value)$this->tables['PedidoItems'][]=$value;$this->write();return [];}
    public function batchUpdateValues(array $data): array{$this->write();return [];}
    private function write(): void{$this->writes++;if($this->failAfterWrite!==null&&$this->writes===$this->failAfterWrite)throw new \RuntimeException('induced');}
}
