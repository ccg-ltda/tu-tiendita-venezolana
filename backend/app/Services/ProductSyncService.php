<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

final class ProductSyncService
{
    public function __construct(private readonly GoogleSheetsProductStore $remote, private readonly ProductOutboxStore $outbox) {}
    public function sync(array $operation): bool
    {
        try {
            $type=$operation['type']; $id=(int)$operation['product_id']; $remote=$this->remote->find($id);
            if($type==='CREATE'){
                if(!$remote){$this->remote->append($operation['product']);return true;}
                if($this->matches($remote,$operation['product']))return true;
                if($remote['revision']>$operation['target_revision'])throw new ProductSheetsException(409,'SYNC_CONFLICT');
                if($remote['revision']<$operation['target_revision']){$this->remote->update($operation['product']);return true;}
                throw new ProductSheetsException(409,'SYNC_CONFLICT');
            }
            if(!$remote)throw new ProductSheetsException(404,'PRODUCT_NOT_FOUND');
            $target=$operation['product']; if($remote['revision']===$target['revision']&&$this->matches($remote,$target))return true;
            if($remote['revision']!==$operation['expected_revision'])throw new ProductSheetsException(409,'SYNC_CONFLICT');
            $this->remote->update($target); return true;
        } catch(\Throwable $e) { Log::warning('Product synchronization pending.', ['operation'=>$operation['type']??'unknown','product_id'=>$operation['product_id']??null,'exception_class'=>$e::class,'classification'=>'remote_failure','message'=>$this->safe($e->getMessage())]); return false; }
    }
    private function matches(array $a,array $b): bool { foreach(['product_id','category','subcategory','name','presentation','price_cop','inventory','active','image_path','legacy_img','revision'] as $k)if(($a[$k]??null)!==($b[$k]??null))return false;return true; }
    private function safe(string $m): string { return mb_strimwidth(trim(preg_replace('/https?:\/\/\S+/i','[redacted]',str_replace(["\r","\n"],' ',$m))??''),0,300,'...'); }
}
