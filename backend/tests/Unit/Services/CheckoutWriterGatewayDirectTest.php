<?php

namespace Tests\Unit\Services;

use App\Contracts\GoogleSheetsValuesClient;
use App\Repositories\CheckoutSheetsRepository;
use App\Promotions\ProductPromotionPriceResolver;
use App\Services\{AppsScriptCheckoutClient,CheckoutAdminOrderStatusService,CheckoutDirectPreparationService,CheckoutExpiredReservationReleaseService,CheckoutIdSequenceStore,CheckoutJournalStore,CheckoutLock,CheckoutPaymentEventService,CheckoutPaymentEventNormalizer,CheckoutPaymentEventPlanner,CheckoutPaymentEventStateValidator,CheckoutPayloadCanonicalizer,CheckoutRecoveryService,CheckoutReferenceGenerator,CheckoutReleaseCandidateReader,CheckoutReleaseFingerprint,CheckoutReleaseJournalStore,CheckoutReservationPlanner,CheckoutReservationWriter,CheckoutUtcTimestamp,CheckoutWriterGateway,GoogleSheetsPromotionStore,OrderNotificationOutboxStore};
use Tests\TestCase;

final class CheckoutWriterGatewayDirectTest extends TestCase
{
    private string $directory;
    protected function setUp(): void { parent::setUp();$this->directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'gateway-direct-'.bin2hex(random_bytes(5));config()->set('checkout.writer_backend','direct'); }
    protected function tearDown(): void { foreach(glob($this->directory.'/*')?:[] as $file)@unlink($file);@rmdir($this->directory);parent::tearDown(); }

    public function test_direct_gateway_routes_prepare_payment_and_admin_without_apps_script(): void
    {
        $gateway=$this->gateway($this->tables());$prepared=$gateway->prepareCheckout(['customer'=>$this->customer(),'items'=>[['product_id'=>1,'quantity'=>1]],'idempotency_key'=>'11111111-1111-4111-8111-111111111111','payload_hash'=>str_repeat('a',64),'recover_after_ambiguous_prepare'=>false]);
        $event=['id'=>'gateway-direct-payment','reference'=>$prepared['reference'],'status'=>'APPROVED','payment_method'=>'CARD','amount_in_cents'=>$prepared['total_cop']*100,'currency'=>'COP','event_occurred_at'=>now('UTC')->format('Y-m-d\\TH:i:s.v\\Z')];
        $payment=$gateway->recordPaymentEvent($event);$admin=$gateway->adminUpdateOrderStatus($prepared['order_id'],'PROCESSING');
        $this->assertSame('direct',$gateway->backend());$this->assertSame('APPROVED',$payment['event_result']);$this->assertSame('PROCESSING',$admin['status']);
    }
    public function test_direct_gateway_routes_candidate_scan_and_release_without_apps_script(): void
    {
        $gateway=$this->gateway($this->tables(true));$candidates=$gateway->getExpiredReservationCandidates(50);$this->assertCount(1,$candidates);
        $result=$gateway->commitExpiredReservations([['reference'=>$candidates[0]['reference'],'expected_revision'=>$candidates[0]['revision'],'verified_final_attempts'=>[]]]);
        $this->assertCount(1,$result['released']);$this->assertSame('RELEASED',$result['released'][0]['reservation_status']);
    }
    private function gateway(array $tables): CheckoutWriterGateway
    {
        $client=new class($tables) implements GoogleSheetsValuesClient {public function __construct(public array $tables){}public function getValues(string $range):array{$sheet=strtok($range,'!');return str_ends_with($range,'1:1')?[$this->tables[$sheet][0]]:$this->tables[$sheet];}public function batchGetValues(array $ranges):array{return array_map(function($range){$sheet=strtok($range,'!');return str_ends_with($range,'1:1')?[$this->tables[$sheet][0]]:$this->tables[$sheet];},$ranges);}public function updateValues(string $range,array $values):array{preg_match('/^([^!]+)!A(\d+):/',$range,$m);$this->tables[$m[1]][(int)$m[2]-1]=$values[0];return [];}public function appendValues(string $range,array $values):array{$sheet=strtok($range,'!');foreach($values as $value)$this->tables[$sheet][]=$value;return [];}public function batchUpdateValues(array $data):array{return [];}};
        $repo=new CheckoutSheetsRepository($client);$lock=new CheckoutLock;$journal=new CheckoutJournalStore($this->directory.'/journal');$sequence=new CheckoutIdSequenceStore($this->directory.'/sequences.json');$canonical=new CheckoutPayloadCanonicalizer;
        $prepare=new CheckoutDirectPreparationService($lock,$canonical,$journal,new CheckoutRecoveryService($lock,$journal,$repo),new CheckoutReservationPlanner($repo,new GoogleSheetsPromotionStore($client),new ProductPromotionPriceResolver,$canonical,new CheckoutReferenceGenerator,$sequence),new CheckoutReservationWriter($lock,$journal,$repo),$repo);
        $payment=new CheckoutPaymentEventService(new CheckoutLock,$repo,$sequence,new OrderNotificationOutboxStore($this->directory.'/notifications.json'),new CheckoutPaymentEventNormalizer,new CheckoutPaymentEventStateValidator,new CheckoutPaymentEventPlanner);
        $reader=new CheckoutReleaseCandidateReader($repo,new CheckoutUtcTimestamp);$release=new CheckoutExpiredReservationReleaseService(new CheckoutLock,$repo,new CheckoutReleaseJournalStore($this->directory.'/release'),new CheckoutReleaseFingerprint,new CheckoutUtcTimestamp);
        return new CheckoutWriterGateway(new AppsScriptCheckoutClient,$prepare,$payment,$reader,$release,new CheckoutAdminOrderStatusService(new CheckoutLock,$repo,new OrderNotificationOutboxStore($this->directory.'/admin-notifications.json'),new CheckoutUtcTimestamp),$repo);
    }
    private function tables(bool $expired=false): array
    {
        $t=now('UTC');$created=$t->copy()->subMinutes(30)->format('Y-m-d\\TH:i:s.v\\Z');$expires=($expired?$t->copy()->subMinutes(20):$t->copy()->addMinutes(10))->format('Y-m-d\\TH:i:s.v\\Z');
        $products=[['product_id','category','subcategory','name','presentation','price_cop','inventory','active','image_path','legacy_img','created_at','updated_at','revision'],[1,'C','S','Product','Unit',24000,5,true,'','',$created,$created,1]];
        $orders=[['order_id','reference','status','payment_status','reservation_status','reservation_expires_at','paid_at','payment_last_event_at','checkout_idempotency_key','checkout_payload_hash','release_id','release_fingerprint','released_at','customer_name','customer_email','customer_phone','customer_document','address','extra','city','region','postal','total_cop','created_at','updated_at','revision']];
        $items=[['order_item_id','order_id','product_id','product_name','unit_price_cop','quantity','created_at']];
        if($expired){$orders[]=[1,'TTV-GATEWAY-RELEASE','PENDING','PENDING','ACTIVE',$expires,'','','11111111-1111-4111-8111-111111111111',str_repeat('b',64),'','','','N','e@example.test','1','1','A','','C','R','',24000,$created,$created,1];$items[]=[1,1,1,'Product',24000,1,$created];}
        return ['Productos'=>$products,'Promociones'=>[['product_id','active','discount_type','discount_value','starts_at','ends_at','updated_at','revision']],'Pedidos'=>$orders,'PedidoItems'=>$items,'Pagos'=>[['payment_attempt_id','order_id','wompi_transaction_id','status','payment_method','amount_in_cents','currency','created_at','updated_at']]];
    }
    private function customer(): array { return ['name'=>'Name','email'=>'e@example.test','phone'=>'+573000000000','document'=>'123','address'=>'Address','extra'=>null,'city'=>'City','region'=>'Region','postal'=>null]; }
}
