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
        $this->post_("/orders/$oid/handover", ['courier' => 'steadfast', 'tracking_id' => 'SF123'])->assertSessionHasNoErrors();

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
}
