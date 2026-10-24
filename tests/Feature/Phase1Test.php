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

    public function test_advance_payment_cod_statement_import_and_short_payments(): void {
        // Order total = 2 x 500 + 60 delivery = 1060 tk. 200 tk advance via bKash -> COD 860.
        $a = $this->order('01811111111', 2);
        $this->post_("/orders/$a/payments", ['method' => 'bkash', 'amount_minor' => 20000, 'reference' => 'TRX1'])->assertSessionHasNoErrors();
        $this->post_("/orders/$a/payments", ['method' => 'bkash', 'amount_minor' => 100, 'reference' => 'TRX1'])->assertSessionHasErrors('inventory'); // same trx
        $this->post_("/orders/$a/payments", ['method' => 'nagad', 'amount_minor' => 999999])->assertSessionHasErrors('inventory'); // more than total
        $b = $this->order('01822222222', 1); $c = $this->order('01833333333', 1);
        $this->ship($a, 'SA'); $this->ship($b, 'SB'); $this->ship($c, 'SC');
        $this->assertSame([86000, 56000], [(int) DB::table('shipments')->where('tracking_id', 'SA')->value('cod_expected_minor'), (int) DB::table('shipments')->where('tracking_id', 'SB')->value('cod_expected_minor')]);

        // Statement: A paid in full, B short by 60 tk (matched by invoice = order no.), unknown row, C not in it.
        $key = (string) Str::uuid();
        $stmt = ['idempotency_key' => $key, 'courier' => 'steadfast', 'reference' => 'INV-1', 'paid_on' => '2026-10-24', 'mark_delivered' => true, 'lines' => [
            ['tracking' => 'SA', 'collected' => 86000, 'charge' => 7000], ['invoice' => "#$b", 'collected' => 50000, 'charge' => 7000], ['tracking' => 'NOPE', 'collected' => 100]]];
        $this->post_('/cod/import', $stmt)->assertSessionHasNoErrors()->assertSessionHas('report', fn ($r) => count($r) === 1 && str_contains($r[0], 'NOPE'));
        $this->post_('/cod/import', $stmt)->assertSessionHasNoErrors(); // replay
        $this->assertSame(1, DB::table('courier_payments')->count());
        $p = DB::table('courier_payments')->first();
        $this->assertSame([2, 136000, 14000, 122000], [(int) $p->parcel_count, (int) $p->collected_minor, (int) $p->charge_minor, (int) $p->net_minor]);
        $this->assertSame(['delivered', 'delivered', 'shipped'], [DB::table('sales_orders')->where('id', $a)->value('status'), DB::table('sales_orders')->where('id', $b)->value('status'), DB::table('sales_orders')->where('id', $c)->value('status')]);
        // A second statement with an already settled parcel saves nothing.
        $this->post_('/cod/import', ['idempotency_key' => (string) Str::uuid(), 'courier' => 'steadfast', 'paid_on' => '2026-10-25', 'lines' => [['tracking' => 'SA', 'collected' => 1]]])
            ->assertSessionHas('error');
        // C delivered via the courier hub but not paid yet.
        $this->post_('/courier/delivered', ['courier' => 'steadfast', 'tracking_ids' => ['SC']]);

        $t = $this->get_('/cod')->assertOk()->viewData('page')['props'];
        $this->assertSame([3, 198000, 136000, 14000, 122000, 1, 56000, 1, 6000],
            [$t['totals']['delivered_parcels'], $t['totals']['delivered_expected'], $t['totals']['collected'], $t['totals']['charges'], $t['totals']['net'],
             $t['totals']['unsettled_parcels'], $t['totals']['unsettled_expected'], $t['totals']['short_parcels'], $t['totals']['short_amount']]);
        $this->assertSame($c, $t['unsettled'][0]['order_id']);
        $this->assertSame($b, $t['short'][0]['order_id']);
        $this->get_('/cod/import')->assertOk();
        $this->get_("/orders/$a")->assertOk()->assertInertia(fn ($p) => $p->has('order.payments', 1)->where('order.shipment.cod_expected_minor', 86000));
    }
}
