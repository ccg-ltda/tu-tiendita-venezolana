<?php

namespace Tests\Unit\Services;

use App\Contracts\GoogleSheetsValuesClient;
use App\Repositories\CheckoutSheetsRepository;
use App\Services\CheckoutExpiredReservationReleaseService;
use App\Services\CheckoutLock;
use App\Services\CheckoutReleaseFingerprint;
use App\Services\CheckoutReleaseJournalStore;
use App\Services\CheckoutUtcTimestamp;
use Tests\TestCase;

final class CheckoutExpiredReservationReleaseReplayTest extends TestCase
{
    private string $directory;
    protected function setUp():void{parent::setUp();$this->directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'release-replay-'.bin2hex(random_bytes(5));}
    protected function tearDown():void{foreach(glob($this->directory.DIRECTORY_SEPARATOR.'*')?:[] as $file)@unlink($file);@rmdir($this->directory);parent::tearDown();}
    public function test_successful_release_replays_without_a_second_inventory_or_order_write():void
    {
        $client=new class($this->tables()) implements GoogleSheetsValuesClient {public int $writes=0;public function __construct(public array $tables){}public function getValues(string $range):array{return [];}public function batchGetValues(array $ranges):array{return array_map(function($range){$s=explode('!',$range)[0];return str_ends_with($range,'1:1')?[array_values($this->tables[$s][0])]:$this->tables[$s];},$ranges);}public function updateValues(string $range,array $values):array{$this->writes++;preg_match('/^([^!]+)!A(\d+):/',$range,$m);$this->tables[$m[1]][(int)$m[2]-1]=$values[0];return [];}public function appendValues(string $range,array $values):array{return [];}public function batchUpdateValues(array $data):array{return [];}};
        $repo=new CheckoutSheetsRepository($client);$store=new CheckoutReleaseJournalStore($this->directory,new CheckoutUtcTimestamp);
        $service=new CheckoutExpiredReservationReleaseService(new CheckoutLock,$repo,$store,new CheckoutReleaseFingerprint,new CheckoutUtcTimestamp);
        $now=new \DateTimeImmutable('2026-09-25T12:20:00.111Z');$first=$service->release('TTV-TEST',1,[],$now);
        $this->assertSame('RELEASED',$first['result']);$product=$repo->findProductById(7);$order=$repo->readOrderById(17);$writes=$client->writes;
        $second=$service->release('TTV-TEST',1,[],$now);
        $this->assertSame('REPLAY',$second['result']);$this->assertSame($writes,$client->writes);$this->assertSame(3,$product['inventory']);$this->assertSame(2,$product['revision']);$this->assertSame('RELEASED',$order['reservation_status']);$this->assertSame('FINALIZED',$store->load('TTV-TEST')['stage']);
    }
    private function tables():array{$t='2026-09-25T12:00:00.111Z';return ['Productos'=>[['product_id','category','subcategory','name','presentation','price_cop','inventory','active','image_path','legacy_img','created_at','updated_at','revision'],[7,'C','S','P','U',100,1,true,'','',$t,$t,1]],'Pedidos'=>[['order_id','reference','status','payment_status','reservation_status','reservation_expires_at','paid_at','payment_last_event_at','checkout_idempotency_key','checkout_payload_hash','release_id','release_fingerprint','released_at','customer_name','customer_email','customer_phone','customer_document','address','extra','city','region','postal','total_cop','created_at','updated_at','revision'],[17,'TTV-TEST','PENDING','PENDING','ACTIVE','2026-09-25T12:10:00.111Z','','','11111111-1111-4111-8111-111111111111',str_repeat('a',64),'','','','N','a@b.co','1','1','D','','C','R','',100,$t,$t,1]],'PedidoItems'=>[['order_item_id','order_id','product_id','product_name','unit_price_cop','quantity','created_at'],[1,17,7,'P',100,2,$t]],'Pagos'=>[['payment_attempt_id','order_id','wompi_transaction_id','status','payment_method','amount_in_cents','currency','created_at','updated_at']]];}
}
