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

    public function test_reports_sales_profit_products_couriers_stock_and_csv(): void {
        // price 500, cost 300, delivery 60 per order. A: 2 pcs delivered (charge 70). B: 1 pc returned, damaged. C: 1 pc with courier.
        $a = $this->order('01811111111', 2); $b = $this->order('01822222222', 1); $c = $this->order('01833333333', 1);
        $this->order('01844444444', 1); $d = (int) DB::table('sales_orders')->max('id');
        $this->post_("/orders/$d/cancel");
        $this->ship($a, 'RA', 'pathao'); $this->ship($b, 'RB', 'pathao'); $this->ship($c, 'RC', 'redx');
        $this->post_('/cod/import', ['idempotency_key' => (string) Str::uuid(), 'courier' => 'pathao', 'paid_on' => now()->toDateString(), 'mark_delivered' => true,
            'lines' => [['tracking' => 'RA', 'collected' => 106000, 'charge' => 7000]]])->assertSessionHasNoErrors();
        $this->post_('/courier/returns', ['idempotency_key' => (string) Str::uuid(), 'courier' => 'pathao', 'parcels' => [['tracking_id' => 'RB', 'damaged' => true]]])->assertSessionHasNoErrors();

        $p = fn ($tab) => $this->get_("/reports?tab=$tab")->assertOk()->viewData('page')['props']['data'];
        $sales = $p('sales');
        $this->assertSame([3, 4, 3 * 6000 + 4 * 50000, 1], [$sales['totals']['orders'], $sales['totals']['pieces'], $sales['totals']['value'], $sales['totals']['cancelled']]);
        $profit = $p('profit');
        // 1000 sales + 60 delivery - 600 cost - 70 courier = 390 tk; damaged 1 pc at cost 300.
        $this->assertSame([100000, 6000, 60000, 7000, 39000, 30000, 9000], [$profit['revenue'], $profit['delivery_income'], $profit['cogs'], $profit['courier_charges'],
            $profit['gross_profit'], $profit['damaged_cost'], $profit['net_after_losses']]);
        $prod = $p('products');
        $this->assertSame([4, 1, 25.0], [$prod['rows'][0]['qty'], $prod['rows'][0]['returned'], $prod['rows'][0]['return_rate']]);
        $cour = collect($p('couriers')['rows'])->keyBy('courier');
        $this->assertSame([2, 1, 1, 50.0, 7000], [$cour['pathao']['sent'], $cour['pathao']['delivered'], $cour['pathao']['returned'], $cour['pathao']['success'], $cour['pathao']['charges']]);
        $this->assertSame(1, $cour['redx']['open']);
        $stock = $p('stock');
        // 500 opening - 4 sold + 0 back good (damaged) = 496 own pieces (1 reserved? no: all shipped) -> available 496, transit 1, damaged 1
        $this->assertSame([496, 496 * 30000, 496 * 50000, 1, 1], [$stock['totals']['pieces'], $stock['totals']['cost_value'], $stock['totals']['sale_value'], $stock['totals']['transit'], $stock['totals']['damaged']]);

        foreach (['sales', 'profit', 'products', 'couriers', 'stock'] as $tab) {
            $r = $this->get_("/reports?tab=$tab&export=csv")->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
            $this->assertStringStartsWith("\xEF\xBB\xBF", $r->streamedContent());
        }
    }

    public function test_excel_order_import_and_store_webhooks_with_inbox_retry(): void {
        $sku = DB::table('products')->value('sku'); $bc = DB::table('products')->value('barcode');
        $rows = [
            ['line' => 2, 'ref' => 'A1', 'name' => 'Karim', 'phone' => '01711000000', 'address' => 'Mirpur', 'code' => $sku, 'qty' => 2, 'delivery' => 6000],
            ['line' => 3, 'ref' => 'A1', 'name' => 'Karim', 'phone' => '01711000000', 'address' => 'Mirpur', 'code' => $bc, 'qty' => 1],
            ['line' => 4, 'ref' => 'A2', 'name' => 'Rahima', 'phone' => '01811000000', 'address' => 'Agrabad', 'code' => 'NOPE', 'qty' => 1],
            ['line' => 5, 'name' => 'Salma', 'phone' => '01911000000', 'address' => 'Sylhet', 'code' => strtolower($sku), 'qty' => 1, 'price' => 45000, 'discount' => 5000],
        ];
        $this->post_('/orders/import', ['status' => 'confirmed', 'rows' => $rows])->assertSessionHas('success')
            ->assertSessionHas('report', fn ($r) => count($r) === 1 && str_contains($r[0], 'NOPE'));
        $this->assertSame(2, DB::table('sales_orders')->count());
        $a = DB::table('sales_orders')->where('customer_phone', '01711000000')->first();
        $this->assertSame([3, 6000, 'confirmed'], [(int) DB::table('sales_order_items')->where('sales_order_id', $a->id)->sum('quantity'), (int) $a->delivery_charge_minor, $a->status]);
        $this->post_('/orders/import', ['status' => 'confirmed', 'rows' => $rows]); // same file again
        $this->assertSame(2, DB::table('sales_orders')->count());
        $this->assertSame(500 - 3 - 1, (int) DB::table('finished_good_stocks')->value('available_stock'));

        // WooCommerce: secret is generated on the Settings page; forged signature rejected.
        $secret = $this->get_('/settings')->assertOk()->viewData('page')['props']['woo']['secret'];
        $this->assertSame(40, strlen($secret));
        $woo = ['id' => 777, 'number' => '777', 'status' => 'processing', 'payment_method' => 'cod', 'total' => '1060.00', 'shipping_total' => '60.00', 'discount_total' => '0.00',
            'billing' => ['first_name' => 'Nadia', 'last_name' => 'Islam', 'phone' => '+8801712345678', 'address_1' => 'Road 5', 'city' => 'Dhaka'],
            'shipping' => ['first_name' => 'Nadia', 'last_name' => 'Islam', 'address_1' => 'House 9, Road 5', 'city' => 'Dhanmondi'],
            'line_items' => [['sku' => $sku, 'quantity' => 2, 'price' => 500]], 'customer_note' => 'Call before'];
        $send = fn (string $url, array $body, string $header, string $key) => $this->call('POST', $this->base.$url, [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_'.strtoupper(str_replace('-', '_', $header)) => base64_encode(hash_hmac('sha256', json_encode($body), $key, true))], json_encode($body));
        $send('/api/hooks/woocommerce', $woo, 'X-WC-Webhook-Signature', 'wrong')->assertStatus(401);
        $this->assertSame(2, DB::table('sales_orders')->count());
        $send('/api/hooks/woocommerce', $woo, 'X-WC-Webhook-Signature', $secret)->assertOk()->assertJsonPath('status', 'imported');
        $send('/api/hooks/woocommerce', $woo, 'X-WC-Webhook-Signature', $secret)->assertOk(); // duplicate delivery
        $w = DB::table('sales_orders')->where('customer_phone', '01712345678')->first();
        $this->assertSame(['pending', 'website', 6000], [$w->status, $w->source, (int) $w->delivery_charge_minor]);
        $this->assertSame(3, DB::table('sales_orders')->count());
        $this->post($this->base.'/api/hooks/woocommerce', ['webhook_id' => 5])->assertOk(); // WooCommerce ping

        // Gate: only orders whose WooCommerce status is "confirmed" (wc-confirmed) come in, as Confirmed; cancel in Woo releases stock.
        $this->post_('/settings', ['values' => ['store.woo_import_status' => 'wc-Confirmed']])->assertSessionHasNoErrors();
        $avail = (int) DB::table('finished_good_stocks')->value('available_stock');
        $gate = ['id' => 888, 'number' => '888', 'status' => 'processing'] + $woo;
        $send('/api/hooks/woocommerce', $gate, 'X-WC-Webhook-Signature', $secret)->assertOk()->assertJsonPath('skipped', true);
        $send('/api/hooks/woocommerce', ['status' => 'pending'] + $gate, 'X-WC-Webhook-Signature', $secret)->assertOk()->assertJsonPath('skipped', true);
        $this->assertSame([3, $avail], [DB::table('sales_orders')->count(), (int) DB::table('finished_good_stocks')->value('available_stock')]);
        $send('/api/hooks/woocommerce', ['status' => 'confirmed'] + $gate, 'X-WC-Webhook-Signature', $secret)->assertOk()->assertJsonPath('status', 'imported');
        $send('/api/hooks/woocommerce', ['status' => 'confirmed'] + $gate, 'X-WC-Webhook-Signature', $secret)->assertOk(); // later updates: no duplicate
        $g = DB::table('sales_orders')->latest('id')->first();
        $this->assertSame([4, 'confirmed', $avail - 2], [DB::table('sales_orders')->count(), $g->status, (int) DB::table('finished_good_stocks')->value('available_stock')]);
        $send('/api/hooks/woocommerce', ['status' => 'cancelled'] + $gate, 'X-WC-Webhook-Signature', $secret)->assertOk()->assertJsonPath('cancelled', true);
        $this->assertSame(['cancelled', $avail], [DB::table('sales_orders')->where('id', $g->id)->value('status'), (int) DB::table('finished_good_stocks')->value('available_stock')]);
        $this->post_('/settings', ['values' => ['store.woo_import_status' => '']]);
        $hooks = collect($this->get_('/orders/import')->viewData('page')['props']['hooks']);
        $this->assertTrue($hooks->contains(fn ($h) => str_starts_with($h['result'], 'waiting')));
        $this->assertTrue($hooks->contains(fn ($h) => str_starts_with($h['result'], 'imported as order')));
        $this->assertTrue($hooks->contains(fn ($h) => $h['result'] === 'wrong secret / signature' && $h['status'] === 401));

        // Shopify: not configured -> 404; paid order with unknown SKU -> inbox failed -> fix SKU -> retry imports with payment.
        $shop = ['id' => 9001, 'name' => '#1001', 'financial_status' => 'paid', 'total_price' => '500.00', 'total_discounts' => '0.00', 'shipping_lines' => [],
            'payment_gateway_names' => ['bkash'], 'shipping_address' => ['name' => 'Tania', 'phone' => '01612345678', 'address1' => 'Uttara', 'city' => 'Dhaka'],
            'line_items' => [['sku' => 'NEW-SKU', 'quantity' => 1, 'price' => '500.00']]];
        $send('/api/hooks/shopify', $shop, 'X-Shopify-Hmac-Sha256', 'k')->assertNotFound();
        $this->post_('/settings', ['secrets' => ['store.shopify_secret' => 'shpss_test'], 'values' => ['orders.web_status' => 'confirmed']])->assertSessionHasNoErrors();
        $send('/api/hooks/shopify', $shop, 'X-Shopify-Hmac-Sha256', 'shpss_test')->assertOk()->assertJsonPath('status', 'failed');
        $in = DB::table('incoming_orders')->where('source', 'shopify')->first();
        $this->assertStringContainsString('NEW-SKU', $in->error);
        DB::table('products')->update(['sku' => 'NEW-SKU']);
        $this->post_("/orders/import/{$in->id}/retry")->assertSessionHas('success');
        $sh = DB::table('sales_orders')->where('customer_phone', '01612345678')->first();
        $this->assertSame('confirmed', $sh->status);
        $this->assertSame([50000, 'bkash'], [(int) DB::table('order_payments')->where('sales_order_id', $sh->id)->value('amount_minor'), DB::table('order_payments')->where('sales_order_id', $sh->id)->value('method')]);
        $this->get_('/orders/import')->assertOk()->assertInertia(fn ($p) => $p->has('inbox', 3)->where('failed', 0));

        // Settings never send saved secrets back.
        $res = $this->get_('/settings')->assertOk()->assertDontSee('shpss_test');
        $props = $res->viewData('page')['props'];
        $this->assertTrue($props['secrets']['store.shopify_secret']);
        $this->assertArrayNotHasKey('store.shopify_secret', $props['values']);
    }

    public function test_sms_on_confirm_ship_deliver_with_gateway_and_retry(): void {
        \Illuminate\Support\Facades\Http::fake(['bulksmsbd.net/*' => \Illuminate\Support\Facades\Http::sequence()
            ->push(['response_code' => 202, 'message_id' => 1])->push(['response_code' => 202])->push(['response_code' => 1007, 'error_message' => 'Balance low'])
            ->push(['response_code' => 202]), 'api.sms.net.bd/*' => ['error' => 0, 'msg' => 'ok']]);
        // Off by default: no SMS.
        $this->order('01711000000');
        $this->assertSame(0, DB::table('sms_logs')->count());
        $this->post_('/settings', ['values' => ['sms.enabled' => '1', 'sms.provider' => 'bulksmsbd', 'sms.sender_id' => 'SHEIKHIT', 'sms.on_confirmed' => '1', 'sms.on_shipped' => '1',
            'sms.on_delivered' => '1', 'sms.tpl_confirmed' => 'Dear {name}, order #{order} ({total} Tk) confirmed. {company}',
            'sms.tpl_shipped' => '#{order} with {courier}, tracking {tracking}, pay {cod} Tk', 'sms.tpl_delivered' => 'Delivered #{order}'],
            'secrets' => ['sms.api_key' => 'KEY123']])->assertSessionHasNoErrors();
        $o = $this->order('+8801811000000', 2);
        $this->ship($o, 'TRK9');
        $this->post_('/courier/delivered', ['courier' => 'steadfast', 'tracking_ids' => ['TRK9']]);
        $logs = DB::table('sms_logs')->where('sales_order_id', $o)->orderBy('id')->get();
        $this->assertSame(['confirmed', 'shipped', 'delivered'], $logs->pluck('event')->all());
        $this->assertSame('Dear Karim, order #'.$o.' (1,060 Tk) confirmed. Sheikh IT', $logs[0]->message);
        $this->assertSame("#$o with Steadfast, tracking TRK9, pay 1,060 Tk", $logs[1]->message);
        $this->assertSame(['sent', 'sent', 'failed'], $logs->pluck('status')->all());
        $this->assertSame('Balance low', $logs[2]->error);
        \Illuminate\Support\Facades\Http::assertSent(fn ($req) => str_contains($req->url(), 'bulksmsbd.net/api/smsapi') && $req['number'] === '8801811000000' && $req['api_key'] === 'KEY123' && $req['senderid'] === 'SHEIKHIT');
        // Same event again does not send twice; the scheduler resends the failed one.
        $this->travel(2)->minutes();
        $this->artisan('opryx:sms-retry')->assertSuccessful();
        $this->assertSame('sent', DB::table('sms_logs')->where('id', $logs[2]->id)->value('status'));
        // Test SMS from settings with another provider; bad numbers are refused.
        $this->post_('/settings', ['values' => ['sms.provider' => 'smsnetbd']]);
        $this->postJson($this->base.'/settings/sms-test', ['phone' => '01911000000'])->assertOk();
        $this->postJson($this->base.'/settings/sms-test', ['phone' => '12345'])->assertStatus(422);
        $this->get_('/settings')->assertOk()->assertInertia(fn ($p) => $p->has('smsLog', 3));
    }
}
