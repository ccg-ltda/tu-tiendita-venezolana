<?php

namespace Tests\Unit\Repositories;

use App\Repositories\ProductSheetsRepository;
use App\Services\CatalogSnapshotStore;
use App\Services\CheckoutLock;
use App\Services\GoogleSheetsProductStore;
use App\Services\ProductOutboxStore;
use App\Services\ProductSyncService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

final class ProductSheetsRepositoryCheckoutLockTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();$this->directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'product-checkout-lock-'.bin2hex(random_bytes(8));
    }
    protected function tearDown(): void
    {
        $catalog=$this->directory.DIRECTORY_SEPARATOR.'catalog';foreach(glob($catalog.DIRECTORY_SEPARATOR.'*')?:[] as $file)@unlink($file);@rmdir($catalog);foreach(glob($this->directory.DIRECTORY_SEPARATOR.'*')?:[] as $file)@unlink($file);@rmdir($this->directory);parent::tearDown();
    }
    public function test_update_reloads_and_writes_the_existing_product_inside_the_checkout_lock(): void
    {
        $before=$this->product(1,5,true);$after=$this->product(2,4,true);
        $remote=Mockery::mock(GoogleSheetsProductStore::class);
        $remote->shouldReceive('find')->twice()->with(7)->andReturnUsing(function()use($before){$probe=Cache::lock(CheckoutLock::NAME,1);$this->assertFalse($probe->get());return $before;});
        $remote->shouldReceive('update')->once()->withArgs(static fn(array $written):bool=>$written['product_id']===7&&$written['inventory']===4&&$written['revision']===2)->andReturnUsing(function(array $written){$probe=Cache::lock(CheckoutLock::NAME,1);$this->assertFalse($probe->get());return $written;});
        $snapshot=new CatalogSnapshotStore($this->directory.DIRECTORY_SEPARATOR.'catalog');$snapshot->writeAtomically([$before]);
        $outbox=new ProductOutboxStore($this->directory.DIRECTORY_SEPARATOR.'outbox.json');$lock=new CheckoutLock;
        $repository=new ProductSheetsRepository($snapshot,$remote,$outbox,new ProductSyncService($remote,$outbox,$lock),$lock);
        $result=$repository->update(7,1,['inventory'=>4]);
        $this->assertSame(4,$result['inventory']);$this->assertSame(2,$result['revision']);$this->assertSame('synced',$repository->syncStatus());
    }
    /** @return array<string,mixed> */
    private function product(int $revision,int $inventory,bool $active): array
    { return ['product_id'=>7,'category'=>'Despensa','subcategory'=>'Harinas','name'=>'Harina','presentation'=>'1 kg','price_cop'=>5000,'inventory'=>$inventory,'active'=>$active,'image_path'=>null,'legacy_img'=>null,'created_at'=>'2026-09-27T10:00:00.000Z','updated_at'=>'2026-09-27T10:00:00.000Z','revision'=>$revision]; }
}
