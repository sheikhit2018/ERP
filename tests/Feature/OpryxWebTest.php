<?php
namespace Tests\Feature;

use App\Models\User;
use App\Support\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** End-to-end checks of the Inertia screens on a tenant host (run on PostgreSQL/MySQL, not SQLite). */
class OpryxWebTest extends TestCase {
    use RefreshDatabase;

    private string $base = 'http://erp.test';
    private int $tenant;
    private User $owner;

    protected function setUp(): void {
        parent::setUp();
        $this->withoutVite();
        $this->tenant = TenantProvisioner::create('Sheikh IT', 'erp.test', 'Owner', 'owner@example.com', 'secret-pass');
        $this->owner = User::where('email', 'owner@example.com')->firstOrFail();
    }

    private function get_(string $path) { return $this->get($this->base.$path); }
    private function post_(string $path, array $data = []) { return $this->post($this->base.$path, $data); }

    public function test_login_and_every_screen_renders(): void {
        $this->post_('/login', ['email' => 'owner@example.com', 'password' => 'secret-pass'])->assertRedirect($this->base.'/dashboard');
        foreach (['/dashboard', '/products', '/raw-materials', '/boms', '/manufacturing', '/orders', '/orders/create', '/returns', '/stock-ledger', '/team', '/profile'] as $page) {
            $this->get_($page)->assertOk();
        }
    }

    public function test_unknown_host_is_404_and_guest_is_redirected(): void {
        $this->get('http://nobody.test/dashboard')->assertNotFound();
        $this->get_('/dashboard')->assertRedirect();
    }

    public function test_full_order_flow_through_the_screens(): void {
        $this->actingAs($this->owner);
        $this->post_('/products', ['sku' => 'TSHIRT-M', 'name' => 'T-Shirt M', 'price_minor' => 55000, 'cost_minor' => 30000, 'low_stock_level' => 2, 'opening_stock' => 10])
            ->assertSessionHasNoErrors();
        $pid = DB::table('products')->where('sku', 'TSHIRT-M')->value('id');

        $this->post_("/products/$pid/adjust", ['delta' => 5, 'reason' => 'purchase'])->assertSessionHasNoErrors();
        $this->assertSame(15, (int) DB::table('finished_good_stocks')->where('product_id', $pid)->value('available_stock'));

        $key = (string) Str::uuid();
        $payload = ['idempotency_key' => $key, 'status' => 'confirmed', 'source' => 'facebook',
            'customer' => ['name' => 'Rahim', 'phone' => '01711111111', 'address' => 'Mirpur, Dhaka'],
            'items' => [['product_id' => $pid, 'quantity' => 3, 'unit_price_minor' => 55000]], 'delivery_charge_minor' => 6000];
        $this->post_('/orders', $payload)->assertRedirect();
        $this->post_('/orders', $payload)->assertRedirect(); // double submit = same order
        $this->assertSame(1, DB::table('sales_orders')->count());
        $oid = DB::table('sales_orders')->value('id');
        $this->assertSame(6000, (int) DB::table('sales_orders')->where('id', $oid)->value('delivery_charge_minor'));

        $this->get_("/orders/$oid")->assertOk();
        $this->get_('/orders?q=01711111111')->assertOk();
        $this->post_("/orders/$oid/handover", ['courier' => 'steadfast', 'tracking_id' => 'SF123'])->assertSessionHasErrors('scans');
        $this->post_("/orders/$oid/handover", ['courier' => 'steadfast', 'tracking_id' => 'SF123', 'scans' => [['product_id' => $pid, 'quantity' => 2]]])->assertSessionHasErrors('inventory');
        $this->post_("/orders/$oid/handover", ['courier' => 'steadfast', 'tracking_id' => 'SF123', 'scans' => [['product_id' => $pid, 'quantity' => 3]]])->assertSessionHasNoErrors();

        $scan = $this->postJson($this->base.'/returns/scan', ['courier_tracking_id' => 'SF123'])->assertOk();
        $this->postJson($this->base.'/returns/'.$scan->json('id').'/qc', ['decision' => 'QC_PASSED'])->assertOk()->assertJsonPath('status', 'passed');
        $this->assertSame(15, (int) DB::table('finished_good_stocks')->where('product_id', $pid)->value('available_stock'));

        // Conflicts come back as a flash message, not a crash page.
        $this->withHeaders(['X-Inertia' => 'true'])->post($this->base."/orders/$oid/cancel")->assertRedirect()->assertSessionHas('error');
    }

    public function test_factory_flow_and_roles(): void {
        $this->actingAs($this->owner);
        $this->post_('/products', ['sku' => 'P1', 'name' => 'Bag', 'price_minor' => 100000])->assertSessionHasNoErrors();
        $this->post_('/raw-materials', ['sku' => 'FAB', 'name' => 'Fabric', 'base_unit' => 'cm', 'opening_stock' => 1000])->assertSessionHasNoErrors();
        $pid = DB::table('products')->value('id'); $mid = DB::table('raw_materials')->value('id');
        $this->post_('/boms', ['product_id' => $pid, 'items' => [['raw_material_id' => $mid, 'quantity_per_unit' => 150]]])->assertSessionHasNoErrors();
        $bom = DB::table('boms')->value('id');
        $this->post_('/manufacturing', ['bom_id' => $bom, 'quantity' => 6, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame(100, (int) DB::table('raw_material_stocks')->value('quantity'));
        $this->assertSame(6, (int) DB::table('finished_good_stocks')->value('available_stock'));
        $this->post_('/manufacturing', ['bom_id' => $bom, 'quantity' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('inventory');

        // A new recipe version archives the old one.
        $this->post_('/boms', ['product_id' => $pid, 'items' => [['raw_material_id' => $mid, 'quantity_per_unit' => 100]]])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('boms')->where('active', true)->count());

        // Team: add a sales user; they cannot open production or team screens.
        $this->post_('/team', ['name' => 'Sales', 'email' => 'sales@example.com', 'password' => 'password1', 'role' => 'sales'])->assertSessionHasNoErrors();
        $sales = User::where('email', 'sales@example.com')->first();
        $this->actingAs($sales);
        $this->get_('/orders')->assertOk();
        $this->get_('/manufacturing')->assertForbidden();
        $this->get_('/team')->assertForbidden();
    }

    public function test_colour_size_variants_barcodes_and_scanned_stock_flow(): void {
        $this->actingAs($this->owner);
        $this->post_('/products', ['code' => 'polo', 'name' => 'Polo T-Shirt', 'price_minor' => 65000,
            'colors' => ['White', 'Black', 'white'], 'sizes' => ['S', 'M', 'L']])->assertSessionHasNoErrors();
        $this->assertSame(6, DB::table('products')->whereNotNull('style_id')->count()); // duplicate "white" ignored
        $style = DB::table('product_styles')->first();
        $this->assertSame('POLO', $style->code);
        $barcodes = DB::table('products')->pluck('barcode')->push($style->barcode);
        $this->assertSame($barcodes->count(), $barcodes->unique()->count());
        $this->assertTrue($barcodes->every(fn ($b) => strlen($b) === 13));

        // Add colour / add size
        $this->post_("/styles/{$style->id}/variants", ['colors' => ['Navy'], 'sizes' => ['S', 'M', 'L']])->assertSessionHasNoErrors();
        $this->post_("/styles/{$style->id}/variants", ['colors' => ['White', 'Black', 'Navy'], 'sizes' => ['XL']])->assertSessionHasNoErrors();
        $this->assertSame(12, DB::table('products')->where('style_id', $style->id)->count());

        // Lookup by style barcode returns the grid; by variant barcode marks it.
        $grid = $this->getJson($this->base.'/scan/lookup?code='.$style->barcode)->assertOk();
        $this->assertCount(12, $grid->json('variants'));
        $whiteM = DB::table('products')->where('color', 'White')->where('size', 'M')->first();
        $this->getJson($this->base.'/scan/lookup?code='.$whiteM->barcode)->assertOk()->assertJsonPath('matched_id', $whiteM->id);
        $this->getJson($this->base.'/scan/lookup?code=000')->assertNotFound();

        // Scanned stock in: White S/M/L 1000/1100/1200, idempotent.
        $ids = DB::table('products')->where('color', 'White')->whereIn('size', ['S', 'M', 'L'])->pluck('id', 'size');
        $key = (string) Str::uuid();
        $in = ['idempotency_key' => $key, 'reason' => 'purchase', 'lines' => [
            ['product_id' => $ids['S'], 'quantity' => 1000], ['product_id' => $ids['M'], 'quantity' => 1100], ['product_id' => $ids['L'], 'quantity' => 1200]]];
        $this->post_('/stock-in', $in)->assertSessionHasNoErrors();
        $this->post_('/stock-in', $in)->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('stock_receipts')->count());
        $this->assertSame(1100, (int) DB::table('finished_good_stocks')->where('product_id', $ids['M'])->value('available_stock'));
        $this->assertSame(3, DB::table('inventory_movements')->where('reason', 'stock_in_purchase')->count());

        // Order 2 x White/M, scan-verified dispatch and scan-verified return.
        $this->post_('/orders', ['idempotency_key' => (string) Str::uuid(), 'status' => 'confirmed',
            'customer' => ['name' => 'Karim', 'phone' => '01811111111', 'address' => 'Uttara'],
            'items' => [['product_id' => $ids['M'], 'quantity' => 2, 'unit_price_minor' => 65000]]])->assertRedirect();
        $oid = DB::table('sales_orders')->value('id');
        $this->post_("/orders/$oid/handover", ['courier' => 'pathao', 'tracking_id' => 'PT-1', 'scans' => [['product_id' => $ids['S'], 'quantity' => 2]]])->assertSessionHasErrors('inventory');
        $this->post_("/orders/$oid/handover", ['courier' => 'pathao', 'tracking_id' => 'PT-1', 'scans' => [['product_id' => $ids['M'], 'quantity' => 1], ['product_id' => $ids['M'], 'quantity' => 1]]])->assertSessionHasNoErrors();
        $this->assertSame(1098, (int) DB::table('finished_good_stocks')->where('product_id', $ids['M'])->value('available_stock'));
        $rid = $this->postJson($this->base.'/returns/scan', ['courier_tracking_id' => 'PT-1'])->assertOk()->json('id');
        $this->postJson($this->base."/returns/$rid/qc", ['decision' => 'QC_PASSED', 'scans' => [['product_id' => $ids['M'], 'quantity' => 1]]])->assertUnprocessable();
        $this->postJson($this->base."/returns/$rid/qc", ['decision' => 'QC_PASSED', 'scans' => [['product_id' => $ids['M'], 'quantity' => 2]]])->assertOk();
        $this->assertSame(1100, (int) DB::table('finished_good_stocks')->where('product_id', $ids['M'])->value('available_stock'));

        // Custom barcode + label page
        $this->post_("/products/{$ids['S']}/barcode", ['barcode' => '8901234567890'])->assertSessionHasNoErrors();
        $this->post_("/products/{$ids['L']}/barcode", ['barcode' => '8901234567890'])->assertSessionHasErrors('inventory');
        $this->get_('/labels?style='.$style->id)->assertOk();
        $this->get_('/stock-in')->assertOk();
    }

    public function test_opening_quantity_per_colour_and_size_with_own_barcodes(): void {
        $this->actingAs($this->owner);
        $this->post_('/products', ['code' => 'TEE', 'name' => 'Basic Tee', 'price_minor' => 45000,
            'colors' => ['White', 'Black'], 'sizes' => ['S', 'M', 'L'],
            'quantities' => [
                ['color' => 'White', 'size' => 'S', 'quantity' => 1000], ['color' => 'white', 'size' => 'M', 'quantity' => 1100],
                ['color' => 'White', 'size' => 'L', 'quantity' => 1200], ['color' => 'Black', 'size' => 'M', 'quantity' => 1100],
                ['color' => 'Black', 'size' => 'S', 'quantity' => 0], ['color' => 'Pink', 'size' => 'S', 'quantity' => 50],
            ]])->assertSessionHasNoErrors();
        $stock = fn ($c, $s) => (int) DB::table('finished_good_stocks')->join('products', 'products.id', '=', 'finished_good_stocks.product_id')
            ->where('color', $c)->where('size', $s)->value('available_stock');
        $this->assertSame([1000, 1100, 1200, 1100, 0, 0], [$stock('White', 'S'), $stock('White', 'M'), $stock('White', 'L'), $stock('Black', 'M'), $stock('Black', 'S'), $stock('Black', 'L')]);
        $this->assertSame(6, DB::table('products')->distinct()->count('barcode'));
        $this->assertSame(0, DB::table('products')->where('color', 'Pink')->count());
        $r = DB::table('stock_receipts')->first();
        $this->assertSame(['opening', 4400, 4], [$r->reason, (int) $r->total_quantity, (int) $r->line_count]);
        $this->assertSame(4, DB::table('inventory_movements')->where('reason', 'stock_in_opening')->count());

        // New colour with quantities; re-adding an existing combination never adds stock twice.
        $style = DB::table('product_styles')->where('code', 'TEE')->first();
        $this->post_("/styles/{$style->id}/variants", ['colors' => ['Navy', 'White'], 'sizes' => ['S', 'M', 'L'],
            'quantities' => [['color' => 'Navy', 'size' => 'M', 'quantity' => 300], ['color' => 'White', 'size' => 'S', 'quantity' => 999]]])->assertSessionHasNoErrors();
        $this->assertSame([300, 1000], [$stock('Navy', 'M'), $stock('White', 'S')]);
        $this->post_('/products', ['code' => 'BAD', 'name' => 'Bad', 'price_minor' => 1, 'colors' => ['A'], 'sizes' => ['S'],
            'quantities' => [['color' => 'A', 'size' => 'S', 'quantity' => -5]]])->assertSessionHasErrors('quantities.0.quantity');

        $this->get_('/labels?style='.$style->id.'&copies=stock')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Products/Labels')->where('copies', 'stock')->has('items', 9)->where('items.0.available', 1000));
        $this->get_('/barcodes')->assertNotFound();
    }
}
