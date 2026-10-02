<?php

namespace Tests\Unit\Services;

use App\Contracts\GoogleSheetsValuesClient;
use App\Repositories\CheckoutSheetsRepository;
use App\Services\CheckoutAdminOrderStatusService;
use App\Services\CheckoutLock;
use App\Services\CheckoutUtcTimestamp;
use App\Services\OrderNotificationOutboxStore;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class CheckoutAdminOrderStatusServiceTest extends TestCase
{
    private const T0='2026-09-27T10:00:00.000Z';
    private const T1='2026-09-27T10:10:00.000Z';
    private string $outboxPath;

    protected function setUp(): void
    { parent::setUp();$this->outboxPath=sys_get_temp_dir().DIRECTORY_SEPARATOR.'admin-status-notifications-'.bin2hex(random_bytes(5)).'.json'; }
    protected function tearDown(): void
    { @unlink($this->outboxPath);@unlink($this->outboxPath.'-blocked');parent::tearDown(); }

    public function test_replay_has_zero_writes_and_preserves_revision_and_updated_at(): void
    {
        [$service,$client]=$this->service($this->order('PENDING','PENDING','ACTIVE'));
        $result=$service->update(['order_id'=>7,'status'=>'PENDING'],new \DateTimeImmutable(self::T1));
        $this->assertTrue($result['ok']);$this->assertTrue($result['data']['idempotency_replayed']);$this->assertSame(1,$result['data']['revision']);$this->assertSame(self::T0,$result['data']['updated_at']);$this->assertSame(0,$client->writes);
    }
    public function test_pending_to_processing_requires_approved_payment(): void
    {
        [$service,$client]=$this->service($this->order('PENDING','PENDING','ACTIVE'));
        $this->assertSame('PAYMENT_NOT_APPROVED',$service->update(['order_id'=>7,'status'=>'PROCESSING'],new \DateTimeImmutable(self::T1))['error']['code']);$this->assertSame(0,$client->writes);
        [$service,$client]=$this->service($this->order('PENDING','APPROVED','CONSUMED'));
        $result=$service->update(['order_id'=>7,'status'=>'PROCESSING'],new \DateTimeImmutable(self::T1));
        $this->assertTrue($result['ok']);$this->assertSame('PROCESSING',$result['data']['status']);$this->assertSame(2,$result['data']['revision']);$this->assertSame(1,$client->writes);
    }
    public function test_processing_creates_one_preparation_notification_and_replay_does_not_duplicate_it(): void
    {
        [$service,,,$outbox]=$this->service($this->order('PENDING','APPROVED','CONSUMED'));
        $result=$service->update(['order_id'=>7,'status'=>'PROCESSING'],new \DateTimeImmutable(self::T1));
        $this->assertTrue($result['ok']);$this->assertSame('EN_PREPARACION',$outbox->all()[0]['notification_type']);$this->assertSame('EN_PREPARACION:7:customer',$outbox->all()[0]['notification_key']);
        [$replayService]=$this->service($this->order('PROCESSING','APPROVED','CONSUMED'));
        $replay=$replayService->update(['order_id'=>7,'status'=>'PROCESSING'],new \DateTimeImmutable(self::T1));
        $this->assertTrue($replay['data']['idempotency_replayed']);$this->assertCount(1,$outbox->all());
    }
    public function test_processing_notification_normalizes_canonical_numeric_strings_from_sheets(): void
    {
        $order=$this->order('PENDING','APPROVED','CONSUMED');$order[0]='7';$order[22]='5000';$order[25]='1';
        [$service,$client,, $outbox]=$this->service($order);
        $client->tables['PedidoItems'][1]=['1','7','1','Producto','5000','1',self::T0];
        $result=$service->update(['order_id'=>7,'status'=>'PROCESSING'],new \DateTimeImmutable(self::T1));
        $entry=$outbox->all()[0];
        $this->assertTrue($result['ok']);$this->assertSame(7,$entry['order_id']);$this->assertSame(5000,$entry['total_cop']);
        $this->assertSame(['product_id'=>1,'product_name'=>'Producto','unit_price_cop'=>5000,'quantity'=>1],$entry['items'][0]);
    }
    public function test_ready_does_not_create_a_notification(): void
    {
        [$service,,,$outbox]=$this->service($this->order('PROCESSING','APPROVED','CONSUMED'));
        $this->assertTrue($service->update(['order_id'=>7,'status'=>'READY'],new \DateTimeImmutable(self::T1))['ok']);$this->assertSame([],$outbox->all());
    }
    public function test_shipped_and_delivered_create_their_respective_notifications(): void
    {
        foreach([['READY','SHIPPED','EN_CAMINO'],['SHIPPED','DELIVERED','ENTREGADO']] as [$from,$target,$type]){
            @unlink($this->outboxPath);[$service,,,$outbox]=$this->service($this->order($from,'APPROVED','CONSUMED'));
            $this->assertTrue($service->update(['order_id'=>7,'status'=>$target],new \DateTimeImmutable(self::T1))['ok']);$this->assertCount(1,$outbox->all());$this->assertSame($type,$outbox->all()[0]['notification_type']);
        }
    }
    public function test_authoritative_normal_transitions_and_ready_shortcut_pass(): void
    {
        foreach([['PROCESSING','READY'],['READY','SHIPPED'],['SHIPPED','DELIVERED'],['READY','DELIVERED']] as [$from,$to]){
            [$service,$client]=$this->service($this->order($from,'APPROVED','CONSUMED'));
            $result=$service->update(['order_id'=>7,'status'=>$to],new \DateTimeImmutable(self::T1));
            $this->assertTrue($result['ok']);$this->assertSame($to,$result['data']['status']);$this->assertSame(2,$result['data']['revision']);$this->assertSame(1,$client->writes);
        }
    }
    public function test_invalid_and_terminal_transitions_are_rejected_without_writes(): void
    {
        foreach([['PENDING','SHIPPED'],['CANCELLED','PENDING'],['DELIVERED','PENDING']] as [$from,$to]){
            [$service,$client]=$this->service($this->order($from,'APPROVED','CONSUMED'));
            $this->assertSame('INVALID_STATUS_TRANSITION',$service->update(['order_id'=>7,'status'=>$to],new \DateTimeImmutable(self::T1))['error']['code']);$this->assertSame(0,$client->writes);
        }
    }
    public function test_rejected_or_failed_status_write_does_not_enqueue_notification(): void
    {
        [$service,,,$outbox]=$this->service($this->order('PENDING','APPROVED','CONSUMED'));
        $this->assertSame('INVALID_STATUS_TRANSITION',$service->update(['order_id'=>7,'status'=>'SHIPPED'],new \DateTimeImmutable(self::T1))['error']['code']);$this->assertSame([],$outbox->all());
        [$repository,$client]=$this->repository($this->order('PENDING','APPROVED','CONSUMED'));$client->corruptStatusAfterWrite=true;$service=new CheckoutAdminOrderStatusService(new CheckoutLock,$repository,new OrderNotificationOutboxStore($this->outboxPath),new CheckoutUtcTimestamp);
        $this->assertSame('CONSISTENCY_UNCERTAIN',$service->update(['order_id'=>7,'status'=>'PROCESSING'],new \DateTimeImmutable(self::T1))['error']['code']);$this->assertSame([],$outbox->all());
    }
    public function test_outbox_failure_happens_after_verified_status_persistence_without_rollback(): void
    {
        $blocked=$this->outboxPath.'-blocked';file_put_contents($blocked,'not a directory');[$repository,$client]=$this->repository($this->order('PENDING','APPROVED','CONSUMED'));
        $service=new CheckoutAdminOrderStatusService(new CheckoutLock,$repository,new OrderNotificationOutboxStore($blocked.'/outbox.json'),new CheckoutUtcTimestamp);
        $result=$service->update(['order_id'=>7,'status'=>'PROCESSING'],new \DateTimeImmutable(self::T1));
        $this->assertSame('INTERNAL_ERROR',$result['error']['code']);$this->assertSame('PROCESSING',$client->tables['Pedidos'][1][2]);$this->assertSame(2,$client->tables['Pedidos'][1][25]);
    }
    public function test_cancel_is_rejected_as_an_invalid_administrative_target_without_writes(): void
    {
        [$service,$client]=$this->service($this->order('PROCESSING','APPROVED','CONSUMED'));
        $result=$service->update(['order_id'=>7,'status'=>'CANCELLED'],new \DateTimeImmutable(self::T1));
        $this->assertFalse($result['ok']);$this->assertSame('INVALID_REQUEST',$result['error']['code']);$this->assertSame(0,$client->writes);
    }
    public function test_transition_preserves_every_non_admin_field(): void
    {
        $before=$this->order('PENDING','APPROVED','CONSUMED');$before[10]='4f4ac525-91d1-4d43-8b91-2a0cb8472999';$before[11]=str_repeat('b',64);$before[12]=self::T0;
        [$service,$client]=$this->service($before);$result=$service->update(['order_id'=>7,'status'=>'PROCESSING'],new \DateTimeImmutable(self::T1));
        $after=$client->tables['Pedidos'][1];
        foreach($before as $index=>$value)if(!in_array($index,[2,24,25],true))$this->assertSame($value,$after[$index]);
        $this->assertSame('PROCESSING',$after[2]);$this->assertSame(self::T1,$after[24]);$this->assertSame(2,$after[25]);$this->assertTrue($result['ok']);
    }
    public function test_read_is_inside_global_lock_and_uses_post_payment_state_not_a_stale_snapshot(): void
    {
        $before=$this->order('PENDING','PENDING','ACTIVE');$afterPayment=$this->order('PENDING','APPROVED','CONSUMED');$afterPayment[6]=self::T0;$afterPayment[7]=self::T0;$afterPayment[25]=2;
        [$service,$client]=$this->service($before);$client->beforeRead=function()use($client,$afterPayment):void{$probe=Cache::lock(CheckoutLock::NAME,1);$this->assertFalse($probe->get());$client->tables['Pedidos'][1]=$afterPayment;};
        $result=$service->update(['order_id'=>7,'status'=>'PROCESSING'],new \DateTimeImmutable(self::T1));
        $final=$client->tables['Pedidos'][1];$this->assertTrue($result['ok']);$this->assertSame('PROCESSING',$final[2]);$this->assertSame('APPROVED',$final[3]);$this->assertSame('CONSUMED',$final[4]);$this->assertSame(3,$final[25]);
    }
    public function test_invalid_revision_or_duplicate_order_fails_closed(): void
    {
        $invalid=$this->order('PENDING','APPROVED','CONSUMED');$invalid[25]='bad';[$service,$client]=$this->service($invalid);
        $this->assertSame('CONSISTENCY_UNCERTAIN',$service->update(['order_id'=>7,'status'=>'PROCESSING'],new \DateTimeImmutable(self::T1))['error']['code']);$this->assertSame(0,$client->writes);
        $duplicate=$this->order('PENDING','APPROVED','CONSUMED');[$service,$client]=$this->service($this->order('PENDING','APPROVED','CONSUMED'),[$duplicate]);
        $this->assertSame('CONSISTENCY_UNCERTAIN',$service->update(['order_id'=>7,'status'=>'PROCESSING'],new \DateTimeImmutable(self::T1))['error']['code']);$this->assertSame(0,$client->writes);
    }
    public function test_post_write_verification_normalizes_expected_and_reread_stored_integers(): void
    {
        $values=$this->order('PENDING','APPROVED','CONSUMED');$values[0]='59';$values[22]='24000';$values[25]='3';[$repository,$client]=$this->repository($values);$client->normalizeStoredOrderIntegersOnWrite=true;
        $after=$repository->writeAdminOrderStatusAfter(2,$values);
        $sent=$client->lastUpdateValues[0];
        $this->assertSame(1,$client->writes);$this->assertSame(59,$sent[0]);$this->assertSame(24000,$sent[22]);$this->assertSame(3,$sent[25]);
        foreach($values as $index=>$value)if(!in_array($index,[0,22,25],true))$this->assertSame($value,$sent[$index]);
        $this->assertSame(59,$after['order_id']);$this->assertSame(24000,$after['total_cop']);$this->assertSame(3,$after['revision']);
    }
    public function test_post_write_verification_rejects_invalid_expected_stored_integer_before_write(): void
    {
        $values=$this->order('PENDING','APPROVED','CONSUMED');$values[25]='3.5';[$repository,$client]=$this->repository($values);
        $this->expectException(\App\Exceptions\CheckoutConsistencyException::class);try{$repository->writeAdminOrderStatusAfter(2,$values);}finally{$this->assertSame(0,$client->writes);}
    }
    public function test_lock_timeout_fails_closed_without_writes(): void
    {
        [$service,$client]=$this->service($this->order('PENDING','APPROVED','CONSUMED'));$held=Cache::lock(CheckoutLock::NAME,60);$this->assertTrue($held->get());
        try{$result=$service->update(['order_id'=>7,'status'=>'PROCESSING'],new \DateTimeImmutable(self::T1));}finally{$held->release();}
        $this->assertSame('LOCK_TIMEOUT',$result['error']['code']);$this->assertSame(0,$client->writes);
    }
    /** @return array{0:CheckoutAdminOrderStatusService,1:object,2:CheckoutSheetsRepository,3:OrderNotificationOutboxStore} */
    private function service(array $order,array $extra=[]): array
    {
        [$repository,$client]=$this->repository($order,$extra);
        $outbox=new OrderNotificationOutboxStore($this->outboxPath);
        return [new CheckoutAdminOrderStatusService(new CheckoutLock,$repository,$outbox,new CheckoutUtcTimestamp),$client,$repository,$outbox];
    }
    /** @return array{0:CheckoutSheetsRepository,1:object} */
    private function repository(array $order,array $extra=[]): array
    {
        $tables=['Pedidos'=>[self::headers(),$order,...$extra],'PedidoItems'=>[['order_item_id','order_id','product_id','product_name','unit_price_cop','quantity','created_at'],[1,7,1,'Producto',5000,1,self::T0]]];
        $client=new class($tables) implements GoogleSheetsValuesClient {public int $writes=0;public array $lastUpdateValues=[];public bool $normalizeStoredOrderIntegersOnWrite=false;public bool $corruptStatusAfterWrite=false;public $beforeRead=null;public function __construct(public array $tables){}public function getValues(string $range):array{return [];}public function batchGetValues(array $ranges):array{if($this->beforeRead){$callback=$this->beforeRead;$this->beforeRead=null;$callback();}return array_map(function(string $range):array{$sheet=explode('!',$range,2)[0];return str_ends_with($range,'1:1')?[$this->tables[$sheet][0]]:$this->tables[$sheet];},$ranges);}public function updateValues(string $range,array $values):array{$this->writes++;$this->lastUpdateValues=$values;preg_match('/^Pedidos!A(\d+):/',$range,$m);$row=$values[0];if($this->normalizeStoredOrderIntegersOnWrite)foreach([0,22,25] as $index)$row[$index]=(int)$row[$index];if($this->corruptStatusAfterWrite)$row[2]='PENDING';$this->tables['Pedidos'][(int)$m[1]-1]=$row;return [];}public function appendValues(string $range,array $values):array{throw new \LogicException('No append.');}public function batchUpdateValues(array $data):array{throw new \LogicException('No batch update.');}};
        return [new CheckoutSheetsRepository($client),$client];
    }
    /** @return list<string> */
    private static function headers(): array
    { return ['order_id','reference','status','payment_status','reservation_status','reservation_expires_at','paid_at','payment_last_event_at','checkout_idempotency_key','checkout_payload_hash','release_id','release_fingerprint','released_at','customer_name','customer_email','customer_phone','customer_document','address','extra','city','region','postal','total_cop','created_at','updated_at','revision']; }
    /** @return list<mixed> */
    private function order(string $status,string $paymentStatus,string $reservationStatus): array
    { $approved=$paymentStatus==='APPROVED';return [7,'TTV-ADMIN',$status,$paymentStatus,$reservationStatus,'2026-09-27T11:00:00.000Z',$approved?self::T0:'',$approved?self::T0:'','11111111-1111-4111-8111-111111111111',str_repeat('a',64),'','','','Name','customer@example.test','+573000000000','1000000000','Address','','City','Region','',5000,self::T0,self::T0,1]; }
}
