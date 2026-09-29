<?php

namespace App\Services;

use App\Exceptions\CheckoutConsistencyException;
use Illuminate\Support\Facades\Log;

/** Durable, PII-free mirror of the Apps Script release journal. */
final class CheckoutReleaseJournalStore
{
    private const STAGES=['PREPARED','PRODUCTS_WRITTEN','PAYMENTS_WRITTEN','ORDER_WRITTEN','FINALIZED'];

    public function __construct(private readonly ?string $directory=null,private readonly ?CheckoutUtcTimestamp $timestamps=null) {}

    /** @param array<string,mixed> $journal @return array<string,mixed> */
    public function create(string $reference,array $journal): array
    { if($this->load($reference)!==null)throw new CheckoutConsistencyException;$this->validate($reference,$journal);$this->write($reference,$journal);return $journal; }
    /** @return array<string,mixed>|null */
    public function load(string $reference): ?array
    { $path=$this->path($reference);if(!is_file($path))return null;try{$data=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new CheckoutConsistencyException;}if(!is_array($data))throw new CheckoutConsistencyException;$this->validate($reference,$data);return $data; }
    /** @return array<string,mixed> */
    public function advance(string $reference,string $stage): array
    { $data=$this->load($reference);if($data===null||!in_array($stage,self::STAGES,true)||array_search($stage,self::STAGES,true)!==array_search($data['stage'],self::STAGES,true)+1)throw new CheckoutConsistencyException;$data['stage']=$stage;$data['updated_at']=now('UTC')->format('Y-m-d\\TH:i:s.v\\Z');$this->write($reference,$data);return $data; }
    public function exists(string $reference): bool{return $this->load($reference)!==null;}
    /** Explicit only: FINALIZED journals are retained; no service calls this automatically. */
    public function delete(string $reference): void
    { $path=$this->path($reference);if(is_file($path)&&!@unlink($path))throw new CheckoutConsistencyException; }

    private function path(string $reference): string
    { $dir=$this->directory??storage_path('app/private/checkout/release-journal');if(!is_dir($dir)&&!@mkdir($dir,0750,true)&&!is_dir($dir))throw new CheckoutConsistencyException;return $dir.DIRECTORY_SEPARATOR.hash('sha256',$reference).'.json'; }
    /** @param array<string,mixed> $data */
    private function write(string $reference,array $data): void
    { try{$json=json_encode($data,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new CheckoutConsistencyException;}$path=$this->path($reference);$tmp=tempnam(dirname($path),'.release-');if($tmp===false||($h=fopen($tmp,'wb'))===false)throw new CheckoutConsistencyException;try{if(fwrite($h,$json)!==strlen($json)||!fflush($h)||(function_exists('fsync')&&!fsync($h)))throw new CheckoutConsistencyException;}finally{fclose($h);}$renameError=null;set_error_handler(static function(int $severity,string $message)use(&$renameError):bool{$renameError=['severity'=>$severity,'message'=>$message];return true;});try{$renamed=rename($tmp,$path);}finally{restore_error_handler();}if(!$renamed){Log::warning('checkout_release_journal_rename_failed',['native_error'=>$renameError,'error_get_last'=>error_get_last(),'source_path'=>$tmp,'destination_path'=>$path,'source_exists'=>is_file($tmp),'destination_exists'=>is_file($path),'source_size'=>is_file($tmp)?@filesize($tmp):null,'destination_size'=>is_file($path)?@filesize($path):null,'source_perms'=>is_file($tmp)?decoct((int)@fileperms($tmp)&0777):null,'destination_perms'=>is_file($path)?decoct((int)@fileperms($path)&0777):null,'directory'=>dirname($path),'directory_perms'=>is_dir(dirname($path))?decoct((int)@fileperms(dirname($path))&0777):null]);@unlink($tmp);throw new CheckoutConsistencyException;} }
    /** @param array<string,mixed> $j */
    private function validate(string $reference,array $j): void
    {
        foreach(['version','reference','order_id','expected_revision','release_id','release_fingerprint','stage','created_at','updated_at','products','payments','expected_order'] as $key)if(!array_key_exists($key,$j))throw new CheckoutConsistencyException;
        if($j['version']!==2||$j['reference']!==$reference||!$this->positive($j['order_id'])||!$this->positive($j['expected_revision'])||!$this->uuid($j['release_id'])||!$this->hash($j['release_fingerprint'])||!in_array($j['stage'],self::STAGES,true)||!is_array($j['products'])||!is_array($j['payments'])||!is_array($j['expected_order']))throw new CheckoutConsistencyException;
        $this->timestamp($j['created_at']);$this->timestamp($j['updated_at']);
        if($j['products']===[])throw new CheckoutConsistencyException;$products=[];foreach($j['products'] as $p){$this->product($p,$products);$products[$p['product_id']]=true;}
        $paymentIds=[];$transactionIds=[];foreach($j['payments'] as $p){$this->payment($p,$j['order_id'],$paymentIds,$transactionIds);$paymentIds[$p['payment_attempt_id']]=true;$transactionIds[$p['wompi_transaction_id']]=true;}
        $this->order($j['expected_order'],$j);
    }
    /** @param array<string,mixed> $p @param array<int,bool> $seen */
    private function product(mixed $p,array $seen): void
    { if(!is_array($p))throw new CheckoutConsistencyException;foreach(['product_id','row_number','inventory_before','inventory_after','revision_before','revision_after','updated_at_before','updated_at_after'] as $k)if(!array_key_exists($k,$p))throw new CheckoutConsistencyException;if(!$this->positive($p['product_id'])||isset($seen[$p['product_id']])||!$this->positive($p['row_number'])||!$this->nonNegative($p['inventory_before'])||!$this->nonNegative($p['inventory_after'])||$p['inventory_after']<$p['inventory_before']||!$this->positive($p['revision_before'])||$p['revision_after']!==$p['revision_before']+1)throw new CheckoutConsistencyException;if($p['updated_at_before']!==null)$this->timestamp($p['updated_at_before']);$this->timestamp($p['updated_at_after']); }
    /** @param array<string,mixed> $p @param array<int,bool> $ids @param array<string,bool> $transactions */
    private function payment(mixed $p,int $orderId,array $ids,array $transactions): void
    { if(!is_array($p))throw new CheckoutConsistencyException;foreach(['payment_attempt_id','order_id','wompi_transaction_id','status_before','status_after','payment_method','amount_in_cents','currency','created_at','updated_at_before','updated_at_after'] as $k)if(!array_key_exists($k,$p))throw new CheckoutConsistencyException;if(!$this->positive($p['payment_attempt_id'])||isset($ids[$p['payment_attempt_id']])||$p['order_id']!==$orderId||!is_string($p['wompi_transaction_id'])||$p['wompi_transaction_id']===''||isset($transactions[$p['wompi_transaction_id']])||$p['status_before']!=='PENDING'||!in_array($p['status_after'],['DECLINED','VOIDED','ERROR'],true)||!is_string($p['payment_method'])||$p['payment_method']===''||!$this->positive($p['amount_in_cents'])||$p['currency']!=='COP')throw new CheckoutConsistencyException;foreach(['created_at','updated_at_before','updated_at_after'] as $key)$this->timestamp($p[$key]); }
    /** @param array<string,mixed> $o @param array<string,mixed> $j */
    private function order(mixed $o,array $j): void
    { if(!is_array($o))throw new CheckoutConsistencyException;foreach(['order_id','reference','status_before','status_after','payment_status_before','payment_status_after','reservation_status_before','reservation_status_after','revision_before','revision_after','release_id','release_fingerprint','released_at_after','updated_at_after','paid_at_after','payment_last_event_at_after','total_cop'] as $k)if(!array_key_exists($k,$o))throw new CheckoutConsistencyException;if($o['order_id']!==$j['order_id']||$o['reference']!==$j['reference']||$o['status_before']!=='PENDING'||$o['status_after']!=='PENDING'||$o['payment_status_before']!=='PENDING'||$o['payment_status_after']!=='PENDING'||$o['reservation_status_before']!=='ACTIVE'||$o['reservation_status_after']!=='RELEASED'||$o['revision_before']!==$j['expected_revision']||$o['revision_after']!==$j['expected_revision']+1||$o['release_id']!==$j['release_id']||$o['release_fingerprint']!==$j['release_fingerprint']||!$this->nonNegative($o['total_cop']))throw new CheckoutConsistencyException;foreach(['released_at_after','updated_at_after'] as $key)$this->timestamp($o[$key]);foreach(['paid_at_after','payment_last_event_at_after'] as $key)if($o[$key]!==null)$this->timestamp($o[$key]); }
    private function timestamp(mixed $value): void { if(!is_string($value))throw new CheckoutConsistencyException;($this->timestamps??new CheckoutUtcTimestamp)->parse($value); }
    private function positive(mixed $value): bool{return is_int($value)&&$value>=1&&$value<=2147483647;}
    private function nonNegative(mixed $value): bool{return is_int($value)&&$value>=0&&$value<=2147483647;}
    private function uuid(mixed $value): bool{return is_string($value)&&preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di',$value)===1;}
    private function hash(mixed $value): bool{return is_string($value)&&preg_match('/^[0-9a-f]{64}$/D',$value)===1;}
}
