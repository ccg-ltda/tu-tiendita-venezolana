<?php

namespace Tests\Unit\Services;

use App\Contracts\GoogleSheetsValuesClient;
use App\Exceptions\CheckoutReservationPlanningException;
use App\Repositories\CheckoutSheetsRepository;
use App\Services\CheckoutIdSequenceStore;
use App\Services\CheckoutPayloadCanonicalizer;
use App\Services\CheckoutReferenceGenerator;
use App\Services\CheckoutReservationPlanner;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CheckoutReservationPlannerTest extends TestCase
{
    public function test_it_plans_normal_and_multiple_item_checkouts_without_writing_sheets(): void
    {
        [$planner,$client]=$this->planner();
        $plan=$planner->plan($this->customer(),[['id'=>7,'qty'=>2],['id'=>8,'qty'=>1]],$this->key(),new DateTimeImmutable('2026-09-25T12:00:00.000Z'));
        $this->assertFalse($plan->idempotencyReplayed);
        $this->assertSame(1,$plan->orderId);
        $this->assertSame(2,$plan->firstOrderItemId);
        $this->assertSame(13000,$plan->totalCop);
        $this->assertSame('2026-09-25T12:10:00.000Z',$plan->reservationExpiresAt);
        $this->assertSame([['product_id'=>7,'quantity'=>2],['product_id'=>8,'quantity'=>1]],$plan->items);
        $this->assertSame(0,$client->writes);
    }
    public function test_it_consolidates_items_and_uses_sheet_price_and_exact_stock(): void
    {
        [$planner]=$this->planner();
        $plan=$planner->plan($this->customer(),[['id'=>7,'qty'=>1],['id'=>7,'qty'=>2]],$this->key(),new DateTimeImmutable('2026-09-25T12:00:00.000Z'));
        $this->assertSame([['product_id'=>7,'quantity'=>3]],$plan->items);
        $this->assertSame(15000,$plan->totalCop);
        $this->assertSame(0,$plan->inventoryPlan[0]['inventory_after']);
    }
    #[DataProvider('unavailableCases')]
    public function test_it_rejects_unavailable_products(string $code, array $items, ?callable $change=null): void
    {
        $tables=$this->tables();if($change)$change($tables);
        [$planner]=$this->planner($tables);
        try{$planner->plan($this->customer(),$items,$this->key(),new DateTimeImmutable('2026-09-25T12:00:00.000Z'));$this->fail('Expected planning error.');}catch(CheckoutReservationPlanningException $exception){$this->assertSame($code,$exception->checkoutCode());}
    }
    public static function unavailableCases(): array
    { return [
        'stock'=>['INSUFFICIENT_STOCK',[['id'=>7,'qty'=>4]],null],
        'not found'=>['PRODUCT_NOT_FOUND',[['id'=>99,'qty'=>1]],null],
        'inactive'=>['PRODUCT_INACTIVE',[['id'=>8,'qty'=>1]],static function (array &$tables): void {$tables['Productos'][2][7]=false;}],
    ]; }
    #[DataProvider('invalidPriceCases')]
    public function test_it_rejects_zero_or_negative_product_price_before_any_sheet_write(int $price): void
    {
        $tables=$this->tables();$tables['Productos'][1][5]=$price;[$planner,$client]=$this->planner($tables);
        try{$planner->plan($this->customer(),[['id'=>7,'qty'=>1]],$this->key(),new DateTimeImmutable('2026-09-25T12:00:00.000Z'));$this->fail('Expected invalid product price.');}catch(CheckoutReservationPlanningException $exception){$this->assertSame('INVALID_PRODUCT_PRICE',$exception->checkoutCode());}
        $this->assertSame(0,$client->writes);
    }
    public static function invalidPriceCases(): array { return ['zero'=>[0],'negative'=>[-1]]; }
    public function test_price_one_is_allowed(): void
    {
        $tables=$this->tables();$tables['Productos'][1][5]=1;[$planner]=$this->planner($tables);
        $this->assertSame(1,$planner->plan($this->customer(),[['id'=>7,'qty'=>1]],$this->key(),new DateTimeImmutable('2026-09-25T12:00:00.000Z'))->totalCop);
    }
    public function test_same_key_replays_without_changing_order_or_reference_and_different_payload_conflicts(): void
    {
        $tables=$this->tables();$tables['Pedidos'][]=$this->order($this->key(),(new CheckoutPayloadCanonicalizer)->canonicalize($this->customer(),[['id'=>7,'qty'=>1]])['payload_hash']);
        [$planner]=$this->planner($tables);
        $replay=$planner->plan($this->customer(),[['id'=>7,'qty'=>1]],$this->key(),new DateTimeImmutable('2026-09-25T12:01:00.000Z'));
        $this->assertTrue($replay->idempotencyReplayed);$this->assertSame(17,$replay->orderId);$this->assertSame('TTV-20260925-H-35AAA0FF',$replay->reference);
        try{$planner->plan($this->customer(),[['id'=>7,'qty'=>2]],$this->key(),new DateTimeImmutable('2026-09-25T12:01:00.000Z'));$this->fail('Expected conflict.');}catch(CheckoutReservationPlanningException $exception){$this->assertSame('IDEMPOTENCY_CONFLICT',$exception->checkoutCode());}
    }
    public function test_expired_reservation_is_not_replayed(): void
    {
        $tables=$this->tables();$hash=(new CheckoutPayloadCanonicalizer)->canonicalize($this->customer(),[['id'=>7,'qty'=>1]])['payload_hash'];$order=$this->order($this->key(),$hash);$order[5]='2026-09-25T11:59:59.999Z';$tables['Pedidos'][]=$order;
        [$planner]=$this->planner($tables);
        try{$planner->plan($this->customer(),[['id'=>7,'qty'=>1]],$this->key(),new DateTimeImmutable('2026-09-25T12:00:00.000Z'));$this->fail('Expected expiry.');}catch(CheckoutReservationPlanningException $exception){$this->assertSame('RESERVATION_EXPIRED',$exception->checkoutCode());}
    }
    private function planner(?array $tables=null): array
    {
        $client=new class($tables??$this->tables()) implements GoogleSheetsValuesClient {
            public int $writes=0;public function __construct(private array $tables) {}
            public function getValues(string $range): array{return [];}
            public function batchGetValues(array $ranges): array{return array_map(function(string $range):array{$sheet=explode('!',$range,2)[0];return str_ends_with($range,'1:1')?[array_values($this->tables[$sheet][0])]:$this->tables[$sheet];},$ranges);}
            public function updateValues(string $range,array $values): array{$this->writes++;return [];}
            public function appendValues(string $range,array $values): array{$this->writes++;return [];}
            public function batchUpdateValues(array $data): array{$this->writes++;return [];}
        };
        $path=sys_get_temp_dir().DIRECTORY_SEPARATOR.'planner-sequences-'.bin2hex(random_bytes(6)).'.json';
        return [new CheckoutReservationPlanner(new CheckoutSheetsRepository($client),new CheckoutPayloadCanonicalizer,new CheckoutReferenceGenerator,new CheckoutIdSequenceStore($path)),$client];
    }
    private function customer(): array{return ['name'=>'Cliente','email'=>'cliente@example.test','phone'=>'+573000000000','document'=>'1000000000','address'=>'Calle 1','extra'=>null,'city'=>'Bogota','region'=>'Bogota','postal'=>null];}
    private function key(): string{return '11111111-1111-4111-8111-111111111111';}
    private function tables(): array
    {return ['Productos'=>[['product_id','category','subcategory','name','presentation','price_cop','inventory','active','image_path','legacy_img','created_at','updated_at','revision'],[7,'Despensa','Harinas','Harina','1 kg',5000,3,true,'','','2026-09-25T12:00:00.000Z','2026-09-25T12:00:00.000Z',1],[8,'Despensa','Aceites','Aceite','1 L',3000,1,true,'','','2026-09-25T12:00:00.000Z','2026-09-25T12:00:00.000Z',2]],'Pedidos'=>[['order_id','reference','status','payment_status','reservation_status','reservation_expires_at','paid_at','payment_last_event_at','checkout_idempotency_key','checkout_payload_hash','release_id','release_fingerprint','released_at','customer_name','customer_email','customer_phone','customer_document','address','extra','city','region','postal','total_cop','created_at','updated_at','revision']],'PedidoItems'=>[['order_item_id','order_id','product_id','product_name','unit_price_cop','quantity','created_at'],[1,17,7,'Harina',5000,1,'2026-09-25T10:00:00.000Z']],'Pagos'=>[['payment_attempt_id','order_id','wompi_transaction_id','status','payment_method','amount_in_cents','currency','created_at','updated_at'],[1,17,'old-payment','DECLINED','CARD',500000,'COP','2026-09-25T10:00:00.000Z','2026-09-25T10:00:00.000Z']]];}
    private function order(string $key,string $hash): array{return [17,'TTV-20260925-H-35AAA0FF','PENDING','PENDING','ACTIVE','2026-09-25T12:10:00.000Z','','',$key,$hash,'','','','Cliente','cliente@example.test','+573000000000','1000000000','Calle 1','','Bogota','Bogota','',5000,'2026-09-25T12:00:00.000Z','2026-09-25T12:00:00.000Z',1];}
}
