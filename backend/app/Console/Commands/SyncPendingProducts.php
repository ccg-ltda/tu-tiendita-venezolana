<?php
namespace App\Console\Commands;
use App\Services\ProductOutboxStore;
use App\Services\ProductSyncService;
use Illuminate\Console\Command;
class SyncPendingProducts extends Command
{
    protected $signature='products:sync-pending';
    protected $description='Synchronizes locally committed product operations with Google Sheets.';
    public function handle(ProductOutboxStore $outbox, ProductSyncService $sync): int
    { $processed=$synced=$pending=$conflicts=0; foreach($outbox->all() as $op){$processed++;if($sync->sync($op)){$outbox->remove($op['operation_id']);$synced++;}else{$op['attempts']=((int)($op['attempts']??0))+1;$op['last_attempt_at']=now('UTC')->toIso8601String();$op['last_error']='remote_sync_failed';$outbox->update($op);$pending++;}}$this->info("Processed: {$processed}");$this->info("Synced: {$synced}");$this->info("Pending: {$pending}");$this->info("Conflicts: {$conflicts}");return $pending>0?self::FAILURE:self::SUCCESS; }
}
