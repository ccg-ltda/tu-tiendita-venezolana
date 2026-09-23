<?php
namespace App\Repositories;
use App\Products\ProductNormalizer;
use App\Services\CatalogSnapshotStore;
use App\Services\GoogleSheetsProductStore;
use App\Services\ProductOutboxStore;
use App\Services\ProductSheetsException;
use App\Services\ProductSyncService;

class ProductSheetsRepository
{
    private ?string $lastSyncStatus = null;
    public function __construct(private readonly CatalogSnapshotStore $snapshot, private readonly GoogleSheetsProductStore $remote, private readonly ProductOutboxStore $outbox, private readonly ProductSyncService $sync) {}
    public function all(): array { return $this->remote->all(); }
    public function syncStatus(): string { return $this->lastSyncStatus ?? 'pending'; }
    public function findById(int $id): ?array { foreach($this->localProducts() as $p)if($p['product_id']===$id)return ['product'=>$p]; return null; }
    public function create(array $input): array
    { $h=$this->lock(); try{$products=$this->localProducts();$max=0;foreach($products as $p)$max=max($max,$p['product_id']);$now=now('UTC')->format('Y-m-d\\TH:i:s.v\\Z');$product=ProductNormalizer::normalize(array_merge($input,['product_id'=>$max+1,'created_at'=>$now,'updated_at'=>$now,'revision'=>1]));if($product===null)throw new ProductSheetsException(422,'INVALID_REQUEST');$this->snapshot->patchProduct($product,false);$op=$this->operation('CREATE',null,$product);$this->outbox->enqueue($op);$this->finishSync($op);return $product;}finally{$this->unlock($h);} }
    public function update(int $id,int $expectedRevision,array $changes): array { return $this->change('UPDATE',$id,$expectedRevision,$changes); }
    public function setActive(int $id,int $expectedRevision,bool $active): array { return $this->change('SET_ACTIVE',$id,$expectedRevision,['active'=>$active]); }
    private function change(string $type,int $id,int $expectedRevision,array $changes): array
    { $h=$this->lock();try{$current=$this->findById($id)['product']??null;if($current===null)throw new ProductSheetsException(404,'PRODUCT_NOT_FOUND');if($current['revision']!==$expectedRevision)throw new ProductSheetsException(409,'REVISION_CONFLICT');$created=$this->pendingCreatedAt($id);if(($current['created_at']??null)===null&&$created!==null)$current['created_at']=$created;$next=ProductNormalizer::normalize(array_merge($current,$changes,['updated_at'=>now('UTC')->format('Y-m-d\\TH:i:s.v\\Z'),'revision'=>$current['revision']+1]));if($next===null)throw new ProductSheetsException(422,'INVALID_REQUEST');$this->snapshot->patchProduct($next,true);$op=$this->operation($type,$expectedRevision,$next);$this->outbox->enqueue($op);$this->finishSync($op);return $next;}finally{$this->unlock($h);} }
    private function pendingCreatedAt(int $id): ?string { foreach($this->outbox->all() as $operation) if(($operation['type']??null)==='CREATE'&&(int)($operation['product_id']??0)===$id) { $value=$operation['product']['created_at']??null; return is_string($value)&&$value!==''?$value:null; } return null; }
    private function localProducts(): array { try{return $this->snapshot->read();}catch(\Throwable){$fresh=$this->remote->all();$doc=$this->snapshot->writeAtomically($fresh);$this->snapshot->replaceCache($doc['products']);return $doc['products'];} }
    private function operation(string $type,?int $expected,array $product): array { return ['operation_id'=>bin2hex(random_bytes(16)),'type'=>$type,'product_id'=>$product['product_id'],'expected_revision'=>$expected,'target_revision'=>$product['revision'],'product'=>$product,'payload'=>$product,'created_at'=>now('UTC')->toIso8601String(),'attempts'=>0,'last_attempt_at'=>null,'last_error'=>null]; }
    private function finishSync(array $op): void { if($this->sync->sync($op)){$this->outbox->remove($op['operation_id']);$this->lastSyncStatus='synced';}else{$this->lastSyncStatus='pending';} }
    private function lock(){ $path=storage_path('app/private/catalog/products.lock');$dir=dirname($path);if(!is_dir($dir))@mkdir($dir,0750,true);$h=@fopen($path,'c');if(!$h||!@flock($h,LOCK_EX))throw new ProductSheetsException(503,'LOCAL_LOCK_UNAVAILABLE');return $h; }
    private function unlock($h):void{@flock($h,LOCK_UN);@fclose($h);}
}
