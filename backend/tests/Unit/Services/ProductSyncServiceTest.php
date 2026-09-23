<?php
namespace Tests\Unit\Services;
use App\Services\GoogleSheetsProductStore;
use App\Services\ProductOutboxStore;
use App\Services\ProductSyncService;
use Mockery;
use Tests\TestCase;

class ProductSyncServiceTest extends TestCase
{
    public function test_create_missing_remote_appends_once(): void
    {
        $product=$this->product(1);$remote=Mockery::mock(GoogleSheetsProductStore::class);$remote->shouldReceive('find')->once()->with(200)->andReturn(null);$remote->shouldReceive('append')->once()->with($product)->andReturn($product);
        $this->assertTrue((new ProductSyncService($remote,new ProductOutboxStore(sys_get_temp_dir().'/unused-outbox.json')))->sync($this->operation('CREATE',$product,null)));
    }
    public function test_create_existing_same_state_does_not_append(): void
    {
        $product=$this->product(4);$remote=Mockery::mock(GoogleSheetsProductStore::class);$remote->shouldReceive('find')->once()->andReturn($product);$remote->shouldNotReceive('append');$remote->shouldNotReceive('update');
        $this->assertTrue((new ProductSyncService($remote,new ProductOutboxStore(sys_get_temp_dir().'/unused-outbox.json')))->sync($this->operation('CREATE',$product,null)));
    }
    public function test_create_existing_lower_revision_updates_same_row(): void
    {
        $product=$this->product(4);$remoteProduct=$product;$remoteProduct['revision']=1;$remote=Mockery::mock(GoogleSheetsProductStore::class);$remote->shouldReceive('find')->once()->andReturn($remoteProduct);$remote->shouldReceive('update')->once()->with($product)->andReturn($product);$remote->shouldNotReceive('append');
        $this->assertTrue((new ProductSyncService($remote,new ProductOutboxStore(sys_get_temp_dir().'/unused-outbox.json')))->sync($this->operation('CREATE',$product,null)));
    }
    public function test_create_remote_greater_revision_is_not_overwritten(): void
    {
        $product=$this->product(4);$remoteProduct=$product;$remoteProduct['revision']=5;$remote=Mockery::mock(GoogleSheetsProductStore::class);$remote->shouldReceive('find')->once()->andReturn($remoteProduct);$remote->shouldNotReceive('append');$remote->shouldNotReceive('update');
        $this->assertFalse((new ProductSyncService($remote,new ProductOutboxStore(sys_get_temp_dir().'/unused-outbox.json')))->sync($this->operation('CREATE',$product,null)));
    }
    private function product(int $revision): array { return ['product_id'=>200,'category'=>'Pruebas','subcategory'=>'Validacion','name'=>'Producto E2E','presentation'=>'Unidad','price_cop'=>1500,'inventory'=>5,'active'=>true,'image_path'=>'/assets/products/e0190519ec63ed088b0b194d581382bc.png','legacy_img'=>null,'created_at'=>'2026-01-01T00:00:00.000Z','updated_at'=>'2026-01-01T00:00:00.000Z','revision'=>$revision]; }
    private function operation(string $type,array $product,?int $expected): array { return ['type'=>$type,'product_id'=>200,'expected_revision'=>$expected,'target_revision'=>$product['revision'],'product'=>$product]; }
}
