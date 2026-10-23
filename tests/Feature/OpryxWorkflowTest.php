<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\{User, FinishedGoodStock};
use App\Tenancy\TenantContext;

// Run on a disposable PostgreSQL/MySQL database. SQLite is intentionally unsupported.
class OpryxWorkflowTest extends TestCase {
    use RefreshDatabase;
    private int $tenant;
    private int $product;
    private int $material;
    private int $bom;
    private User $actor;
    protected function setUp(): void {
        parent::setUp();
        $this->actor = User::factory()->create();
        $this->tenant = DB::table('tenants')->insertGetId(['name'=>'Alpha', 'slug'=>'alpha', 'active'=>true]);
        DB::table('tenant_domains')->insert(['tenant_id'=>$this->tenant, 'hostname'=>'alpha.opryx.test', 'verified_at'=>now()]);
        DB::table('tenant_user')->insert(['tenant_id'=>$this->tenant, 'user_id'=>$this->actor->id, 'role'=>'owner','active'=>true]);
        $this->product = DB::table('products')->insertGetId(['tenant_id'=>$this->tenant,'sku'=>'P1','name'=>'Product','base_unit'=>'piece']);
        $this->material = DB::table('raw_materials')->insertGetId(['tenant_id'=>$this->tenant,'sku'=>'R1','name'=>'Material','base_unit'=>'g']);
        DB::table('raw_material_stocks')->insert(['tenant_id'=>$this->tenant,'raw_material_id'=>$this->material,'quantity'=>100]);
        DB::table('finished_good_stocks')->insert(['tenant_id'=>$this->tenant,'product_id'=>$this->product,'available_stock'=>10]);
        $this->bom = DB::table('boms')->insertGetId(['tenant_id'=>$this->tenant,'product_id'=>$this->product,'version'=>1,'active'=>true]);
        DB::table('bom_items')->insert(['tenant_id'=>$this->tenant,'bom_id'=>$this->bom,'raw_material_id'=>$this->material,'quantity_per_unit'=>20]);
        $this->actingAs($this->actor);
    }
    private function api(string $path, array $data = []) {
        return $this->postJson('http://alpha.opryx.test/api/'.$path, $data);
    }
    private function orderData(int $qty = 3): array {
        return ['idempotency_key'=>(string)Str::uuid(), 'status'=>'confirmed', 'currency'=>'BDT',
            'customer'=>['name'=>'Test','phone'=>'01000','address'=>'Test address'],
            'items'=>[['product_id'=>$this->product,'quantity'=>$qty,'unit_price_minor'=>10000]]];
    }
    private function stock(): object { return DB::table('finished_good_stocks')->where('tenant_id',$this->tenant)->where('product_id',$this->product)->first(); }
    private function shipped(): int {
        $id = $this->api('orders', $this->orderData())->assertOk()->json('id');
        $this->api("orders/$id/handover", ['courier'=>'test','tracking_id'=>'TRACK-1'])->assertOk();
        return $id;
    }
    public function test_manufacturing_is_atomic_and_idempotent(): void {
        $d = ['bom_id'=>$this->bom,'quantity'=>3,'idempotency_key'=>(string)Str::uuid()];
        $this->api('manufacturing-orders',$d)->assertOk();
        $this->api('manufacturing-orders',$d)->assertOk();
        $this->assertEquals(13,$this->stock()->available_stock);
        $this->assertDatabaseHas('raw_material_stocks',['raw_material_id'=>$this->material,'quantity'=>40]);
        $d['idempotency_key'] = (string)Str::uuid();
        $this->api('manufacturing-orders',$d)->assertUnprocessable();
        $this->assertEquals(13,$this->stock()->available_stock);
        $this->assertDatabaseCount('manufacturing_orders',1);
    }
    public function test_stock_cannot_be_oversold_and_key_cannot_change_payload(): void {
        $d = $this->orderData(8);
        $this->api('orders',$d)->assertOk(); $this->api('orders',$d)->assertOk();
        $this->api('orders',$this->orderData(3))->assertUnprocessable();
        $this->assertEquals(2,$this->stock()->available_stock);
        $this->assertEquals(8,$this->stock()->reserved_stock);
        $this->assertDatabaseCount('sales_orders',1);
        $d['items'][0]['quantity'] = 7;
        $this->api('orders',$d)->assertConflict();
    }
    public function test_full_return_and_retries_do_not_double_credit(): void {
        $id = $this->shipped();
        $this->api("orders/$id/handover",['courier'=>'test','tracking_id'=>'TRACK-1'])->assertOk();
        $this->assertEquals(7,$this->stock()->available_stock);
        $this->assertEquals(0,$this->stock()->reserved_stock);
        $this->assertEquals(3,$this->stock()->transit_stock);
        $r = $this->api('courier-returns/scan',['courier_tracking_id'=>'TRACK-1'])->assertOk()->json('id');
        $this->api('courier-returns/scan',['courier_tracking_id'=>'TRACK-1'])->assertOk()->assertJsonPath('id',$r);
        $this->assertEquals(7,$this->stock()->available_stock);
        $this->api("courier-returns/$r/qc",['decision'=>'QC_PASSED'])->assertOk();
        $this->api("courier-returns/$r/qc",['decision'=>'QC_PASSED'])->assertOk();
        $this->api("courier-returns/$r/qc",['decision'=>'DAMAGED'])->assertConflict();
        $this->assertEquals(10,$this->stock()->available_stock);
        $this->assertEquals(0,$this->stock()->transit_stock);
        $this->assertDatabaseHas('sales_orders',['id'=>$id,'status'=>'returned']);
    }
    public function test_damaged_return_is_never_sellable(): void {
        $this->shipped();
        $id = $this->api('courier-returns/scan',['courier_tracking_id'=>'TRACK-1'])->assertOk()->json('id');
        $this->api("courier-returns/$id/qc",['decision'=>'DAMAGED'])->assertOk();
        $this->assertEquals(7,$this->stock()->available_stock);
        $this->assertEquals(3,$this->stock()->damaged_stock);
        $this->assertEquals(0,$this->stock()->transit_stock);
    }
    public function test_cancel_releases_reservation_once(): void {
        $id = $this->api('orders',$this->orderData())->assertOk()->json('id');
        $this->api("orders/$id/cancel")->assertOk(); $this->api("orders/$id/cancel")->assertOk();
        $this->assertEquals(10,$this->stock()->available_stock);
        $this->assertEquals(0,$this->stock()->reserved_stock);
        $this->api("orders/$id/handover",['courier'=>'test','tracking_id'=>'T'])->assertConflict();
    }
    public function test_delivery_clears_transit_and_blocks_courier_return(): void {
        $id = $this->shipped();
        $this->api("orders/$id/delivered")->assertOk(); $this->api("orders/$id/delivered")->assertOk();
        $this->assertEquals(0,$this->stock()->transit_stock);
        $this->assertEquals(7,$this->stock()->available_stock);
        $this->api('courier-returns/scan',['courier_tracking_id'=>'TRACK-1'])->assertConflict();
    }
    public function test_domain_is_not_membership_and_unknown_domains_fail(): void {
        $other = DB::table('tenants')->insertGetId(['name'=>'Beta','slug'=>'beta','active'=>true]);
        DB::table('tenant_domains')->insert(['tenant_id'=>$other,'hostname'=>'beta.opryx.test','verified_at'=>now()]);
        $this->postJson('http://beta.opryx.test/api/orders',$this->orderData())->assertForbidden();
        $this->postJson('http://unknown.opryx.test/api/orders',$this->orderData())->assertNotFound();
    }
    public function test_cross_tenant_product_is_not_reservable(): void {
        $other = DB::table('tenants')->insertGetId(['name'=>'Beta','slug'=>'beta','active'=>true]);
        $p = DB::table('products')->insertGetId(['tenant_id'=>$other,'sku'=>'P2','name'=>'Other','base_unit'=>'piece']);
        DB::table('finished_good_stocks')->insert(['tenant_id'=>$other,'product_id'=>$p,'available_stock'=>100]);
        $d = $this->orderData(); $d['items'][0]['product_id']=$p;
        $this->api('orders',$d)->assertUnprocessable();
        $this->assertDatabaseCount('sales_orders',0);
    }
    public function test_missing_tenant_context_fails_closed(): void {
        app(TenantContext::class)->clear();
        $this->expectException(\LogicException::class);
        FinishedGoodStock::query()->get();
    }
    public function test_database_rejects_cross_tenant_foreign_key(): void {
        $other = DB::table('tenants')->insertGetId(['name'=>'Beta','slug'=>'beta','active'=>true]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('finished_good_stocks')->insert(['tenant_id'=>$other,'product_id'=>$this->product]);
    }
}
