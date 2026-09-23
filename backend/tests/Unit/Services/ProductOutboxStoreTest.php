<?php
namespace Tests\Unit\Services;
use App\Services\ProductOutboxStore;
use Tests\TestCase;

class ProductOutboxStoreTest extends TestCase
{
    private string $path;
    protected function setUp(): void { parent::setUp(); $this->path=sys_get_temp_dir().DIRECTORY_SEPARATOR.'products-outbox-'.bin2hex(random_bytes(6)).'.json'; }
    protected function tearDown(): void { @unlink($this->path); parent::tearDown(); }
    public function test_create_and_multiple_updates_compact_to_one_latest_create(): void
    {
        $store=new ProductOutboxStore($this->path);$base=$this->operation('CREATE',1,null,'2026-01-01T00:00:00.000Z');$store->enqueue($base);
        for($revision=2;$revision<=4;$revision++){$p=$base['product'];$p['revision']=$revision;$p['inventory']=$revision+1;$p['created_at']=null;$p['updated_at']="2026-01-01T00:00:0{$revision}.000Z";$store->enqueue($this->operation('UPDATE',$revision-1,$p,null));}
        $ops=$store->all();$this->assertCount(1,$ops);$this->assertSame('CREATE',$ops[0]['type']);$this->assertSame(4,$ops[0]['target_revision']);$this->assertSame(4,$ops[0]['product']['revision']);$this->assertSame('2026-01-01T00:00:00.000Z',$ops[0]['product']['created_at']);
    }
    public function test_create_and_set_active_compact_to_final_state(): void
    {
        $store=new ProductOutboxStore($this->path);$base=$this->operation('CREATE',1,null,'2026-01-01T00:00:00.000Z');$store->enqueue($base);$p=$base['product'];$p['revision']=2;$p['active']=false;$store->enqueue($this->operation('SET_ACTIVE',2,$p,null));$ops=$store->all();$this->assertCount(1,$ops);$this->assertSame('CREATE',$ops[0]['type']);$this->assertFalse($ops[0]['product']['active']);
    }
    private function operation(string $type,int $revision,?array $product,?string $created): array { $product??=['product_id'=>200,'category'=>'Pruebas','subcategory'=>'Validacion','name'=>'Producto E2E','presentation'=>'Unidad','price_cop'=>1500,'inventory'=>5,'active'=>true,'image_path'=>null,'legacy_img'=>null,'created_at'=>$created,'updated_at'=>$created,'revision'=>1];return ['operation_id'=>bin2hex(random_bytes(8)),'type'=>$type,'product_id'=>200,'expected_revision'=>$type==='CREATE'?null:$revision-1,'target_revision'=>$product['revision'],'product'=>$product,'payload'=>$product,'created_at'=>'2026-01-01T00:00:00+00:00','attempts'=>2,'last_attempt_at'=>null,'last_error'=>'remote_sync_failed']; }
}
