<?php
namespace Tests\Feature;

use App\Models\User;
use App\Support\{Phone, TenantProvisioner};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Phase 1: fraud check, COD reconciliation, reports, order import, SMS, courier APIs. */
class Phase1Test extends TestCase {
    use RefreshDatabase;

    private string $base = 'http://erp.test';
    private int $pid;

    protected function setUp(): void {
        parent::setUp();
        $this->withoutVite();
        TenantProvisioner::create('Sheikh IT', 'erp.test', 'Owner', 'owner@example.com', 'secret-pass');
        $this->actingAs(User::where('email', 'owner@example.com')->firstOrFail());
        $this->post_('/products', ['code' => 'SH', 'name' => 'Shirt', 'price_minor' => 50000, 'cost_minor' => 30000, 'colors' => ['White'], 'sizes' => ['L'],
            'quantities' => [['color' => 'White', 'size' => 'L', 'quantity' => 500]]])->assertSessionHasNoErrors();
        $this->pid = (int) DB::table('products')->value('id');
    }
    private function post_(string $p, array $d = []) { return $this->post($this->base.$p, $d); }
    private function get_(string $p) { return $this->get($this->base.$p); }
    private function order(string $phone, int $qty = 1, string $name = 'Karim'): int {
        $this->post_('/orders', ['idempotency_key' => (string) Str::uuid(), 'status' => 'confirmed', 'customer' => ['name' => $name, 'phone' => $phone, 'address' => 'Mirpur 10, Dhaka'],
            'items' => [['product_id' => $this->pid, 'quantity' => $qty, 'unit_price_minor' => 50000]], 'delivery_charge_minor' => 6000])->assertRedirect();
        return (int) DB::table('sales_orders')->max('id');
    }
    private function ship(int $oid, string $tracking, string $courier = 'steadfast'): void {
        $this->postJson($this->base.'/courier/pack', ['order_id' => $oid, 'scans' => DB::table('sales_order_items')->where('sales_order_id', $oid)->get()
            ->map(fn ($i) => ['product_id' => (int) $i->product_id, 'quantity' => (int) $i->quantity])->all()])->assertOk();
        $this->post_('/courier/dispatch', ['idempotency_key' => (string) Str::uuid(), 'courier' => $courier, 'parcels' => [['order_id' => $oid, 'tracking_id' => $tracking]]])->assertSessionHasNoErrors();
    }

    public function test_phone_normalisation_and_customer_fraud_check(): void {
        $this->assertSame(['01711000000', '01711000000', '01711000000'], [Phone::normalize('+880 1711-000000'), Phone::normalize('8801711000000'), Phone::normalize('1711000000')]);
        // Same customer, 4 orders written 3 different ways: 2 delivered, 2 returned.
        $ids = [$this->order('01711000000'), $this->order('+8801711000000'), $this->order('0171 1000 000'), $this->order('01711-000000')];
        foreach ($ids as $i => $id) $this->ship($id, "T$i");
        $this->post_('/courier/delivered', ['courier' => 'steadfast', 'tracking_ids' => ['T0', 'T1']])->assertSessionHasNoErrors();
        $this->post_('/courier/returns', ['idempotency_key' => (string) Str::uuid(), 'courier' => 'steadfast', 'parcels' => [['tracking_id' => 'T2'], ['tracking_id' => 'T3']]])->assertSessionHasNoErrors();
        $this->order('01999999999', 1, 'New Person');

        $this->getJson($this->base.'/customers/risk?phone=8801711000000')->assertOk()
            ->assertJson(['orders' => 4, 'delivered' => 2, 'returned' => 2, 'success_rate' => 50, 'level' => 'risky'])->assertJsonCount(4, 'recent');
        $this->getJson($this->base.'/customers/risk?phone=01999999999&except='.DB::table('sales_orders')->max('id'))->assertOk()->assertJson(['orders' => 0, 'level' => 'new']);
        $this->getJson($this->base.'/customers/risk?phone=12')->assertStatus(422);
        // One more return makes this customer risky.
        $x = $this->order('01711000000'); $this->ship($x, 'T9');
        $this->post_('/courier/returns', ['idempotency_key' => (string) Str::uuid(), 'courier' => 'steadfast', 'parcels' => [['tracking_id' => 'T9']]])->assertSessionHasNoErrors();
        $this->getJson($this->base.'/customers/risk?phone=01711000000')->assertJson(['returned' => 3, 'success_rate' => 40, 'level' => 'risky']);

        $this->get_('/customers')->assertOk()->assertInertia(fn ($p) => $p->component('Customers/Index')->has('customers.data', 2)
            ->where('customers.data.0.phone', '01711000000')->where('customers.data.0.spent_minor', 100000));
        $this->get_('/customers?q=1711')->assertOk()->assertInertia(fn ($p) => $p->has('customers.data', 1));
        $this->get_('/orders')->assertOk()->assertInertia(fn ($p) => $p->where('risk.01711000000.level', 'risky'));
    }
}
