<?php

namespace Tests\Unit\Repositories;

use App\Contracts\GoogleSheetsValuesClient;
use App\Exceptions\CheckoutConsistencyException;
use App\Repositories\CheckoutSheetsRepository;
use Tests\TestCase;

class CheckoutSheetsRepositoryTest extends TestCase
{
    public function test_it_reads_valid_headers_and_lookups(): void
    {
        $repository=$this->repository();
        $this->assertSame(7,$repository->findProductById(7)['inventory']);
        $this->assertSame(17,$repository->findOrderByIdempotencyKey('11111111-1111-4111-8111-111111111111')['order_id']);
        $this->assertSame('TTV-20260925-H-35AAA0FF',$repository->findOrderByReference('TTV-20260925-H-35AAA0FF')['reference']);
        $this->assertCount(1,$repository->findPaymentsByOrderId(17));
    }
    public function test_it_rejects_invalid_headers(): void
    {
        $tables=$this->tables();$tables['Productos'][0][0]='wrong_header';
        $this->expectException(CheckoutConsistencyException::class);
        $this->repository($tables)->readProducts();
    }
    public function test_it_rejects_duplicate_product_ids(): void
    {
        $tables=$this->tables();$tables['Productos'][]=$tables['Productos'][1];
        $this->expectException(CheckoutConsistencyException::class);
        $this->repository($tables)->readProducts();
    }
    public function test_it_rejects_duplicate_order_ids_and_references(): void
    {
        $tables=$this->tables();$copy=$tables['Pedidos'][1];$copy[1]='TTV-OTHER';$tables['Pedidos'][]=$copy;
        try{$this->repository($tables)->readOrders();$this->fail('Expected duplicate order id rejection.');}catch(CheckoutConsistencyException){}
        $tables=$this->tables();$copy=$tables['Pedidos'][1];$copy[0]=18;$tables['Pedidos'][]=$copy;
        $this->expectException(CheckoutConsistencyException::class);
        $this->repository($tables)->readOrders();
    }
    public function test_it_rejects_duplicate_wompi_transaction_ids(): void
    {
        $tables=$this->tables();$copy=$tables['Pagos'][1];$copy[0]=2;$tables['Pagos'][]=$copy;
        $this->expectException(CheckoutConsistencyException::class);
        $this->repository($tables)->readPayments();
    }
    public function test_payment_event_rows_normalize_formatted_sheet_integers_only(): void
    {
        $tables=$this->tables();$tables['Pagos'][1][0]='1';$tables['Pagos'][1][1]='46';$tables['Pagos'][1][5]='2400000';
        $payment=$this->repository($tables)->readPaymentsForPaymentEvent()[0];
        $this->assertSame(1,$payment['payment_attempt_id']);
        $this->assertSame(46,$payment['order_id']);
        $this->assertSame(2400000,$payment['amount_in_cents']);
    }
    public function test_payment_event_rows_reject_non_canonical_or_unsafe_stored_integers(): void
    {
        foreach(['',null,true,'1.5',1.5,'-1','+1',' 1 ','1e3','2147483648'] as $value){
            $tables=$this->tables();$tables['Pagos'][1][0]=$value;
            try{$this->repository($tables)->readPaymentsForPaymentEvent();$this->fail('Expected stored payment integer rejection.');}catch(CheckoutConsistencyException){$this->addToAssertionCount(1);}
        }
    }
    public function test_payment_event_orders_normalize_formatted_sheet_integers_only(): void
    {
        $tables=$this->tables();$tables['Pedidos'][1][0]='46';$tables['Pedidos'][1][22]='24000';$tables['Pedidos'][1][25]='1';
        $order=$this->repository($tables)->readOrdersForPaymentEvent()[0];
        $this->assertSame(46,$order['order_id']);
        $this->assertSame(24000,$order['total_cop']);
        $this->assertSame(1,$order['revision']);
        $tables['Pedidos'][1][22]='0';
        $this->assertSame(0,$this->repository($tables)->readOrdersForPaymentEvent()[0]['total_cop']);
    }
    public function test_payment_event_orders_reject_non_canonical_or_unsafe_stored_integers(): void
    {
        foreach([0=>['',null,true,'1.5',1.5,'-1','+1',' 1 ','1e3','2147483648'],22=>['',null,true,'1.5',1.5,'-1','+1',' 1 ','1e3','2147483648'],25=>['',null,true,'1.5',1.5,'-1','+1',' 1 ','1e3','2147483648']] as $column=>$values){
            foreach($values as $value){$tables=$this->tables();$tables['Pedidos'][1][$column]=$value;try{$this->repository($tables)->readOrdersForPaymentEvent();$this->fail('Expected stored order integer rejection.');}catch(CheckoutConsistencyException){$this->addToAssertionCount(1);}}
        }
    }
    private function repository(?array $tables=null): CheckoutSheetsRepository
    {
        $tables ??=$this->tables();
        $client=new class($tables) implements GoogleSheetsValuesClient {
            public function __construct(private array $tables) {}
            public function getValues(string $range): array { return []; }
            public function batchGetValues(array $ranges): array { return array_map(function(string $range):array{$sheet=explode('!',$range,2)[0];if(str_ends_with($range,'1:1'))return [array_values($this->tables[$sheet][0])];return $this->tables[$sheet];},$ranges); }
            public function updateValues(string $range,array $values): array { throw new \LogicException('Read-only test double.'); }
            public function appendValues(string $range,array $values): array { throw new \LogicException('Read-only test double.'); }
            public function batchUpdateValues(array $data): array { throw new \LogicException('Read-only test double.'); }
        };
        return new CheckoutSheetsRepository($client);
    }
    private function tables(): array
    {
        return [
            'Productos'=>[['product_id','category','subcategory','name','presentation','price_cop','inventory','active','image_path','legacy_img','created_at','updated_at','revision'],[7,'Despensa','Harinas','Harina','1 kg',5000,7,true,'','', '2026-09-25T12:00:00.000Z','2026-09-25T12:00:00.000Z',1]],
            'Pedidos'=>[['order_id','reference','status','payment_status','reservation_status','reservation_expires_at','paid_at','payment_last_event_at','checkout_idempotency_key','checkout_payload_hash','release_id','release_fingerprint','released_at','customer_name','customer_email','customer_phone','customer_document','address','extra','city','region','postal','total_cop','created_at','updated_at','revision'],[17,'TTV-20260925-H-35AAA0FF','PENDING','PENDING','ACTIVE','2026-09-25T12:10:00.000Z','','','11111111-1111-4111-8111-111111111111',str_repeat('a',64),'','','','Cliente','cliente@example.test','+573000000000','1000000000','Calle 1','','Bogota','Bogota','',5000,'2026-09-25T12:00:00.000Z','2026-09-25T12:00:00.000Z',1]],
            'PedidoItems'=>[['order_item_id','order_id','product_id','product_name','unit_price_cop','quantity','created_at'],[1,17,7,'Harina',5000,1,'2026-09-25T12:00:00.000Z']],
            'Pagos'=>[['payment_attempt_id','order_id','wompi_transaction_id','status','payment_method','amount_in_cents','currency','created_at','updated_at'],[1,17,'transaction-1','PENDING','CARD',500000,'COP','2026-09-25T12:00:00.000Z','2026-09-25T12:00:00.000Z']],
        ];
    }
}
