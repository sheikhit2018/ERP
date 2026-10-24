<?php
namespace Tests\Feature;

use App\Models\User;
use App\Support\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;
use Tests\TestCase;

/** Booking with Steadfast / Pathao / RedX APIs, label-scan handover, webhook and status sync (HTTP faked). */
class CourierApiTest extends TestCase {
    use RefreshDatabase;
    private string $base = 'http://erp.test';
    private int $pid;

    protected function setUp(): void {
        parent::setUp();
        $this->withoutVite();
        TenantProvisioner::create('Sheikh IT', 'erp.test', 'Owner', 'owner@example.com', 'secret-pass');
        $this->actingAs(User::where('email', 'owner@example.com')->firstOrFail());
        $this->post_('/products', ['code' => 'SH', 'name' => 'Shirt', 'price_minor' => 50000, 'colors' => ['White'], 'sizes' => ['L'],
            'quantities' => [['color' => 'White', 'size' => 'L', 'quantity' => 100]]])->assertSessionHasNoErrors();
        $this->pid = (int) DB::table('products')->value('id');
    }
    private function post_(string $p, array $d = []) { return $this->post($this->base.$p, $d); }
    private function order(string $phone, string $address = 'House 5, Mirpur 10, Dhaka'): int {
        $this->post_('/orders', ['idempotency_key' => (string) Str::uuid(), 'status' => 'confirmed', 'customer' => ['name' => 'Karim', 'phone' => $phone, 'address' => $address],
            'items' => [['product_id' => $this->pid, 'quantity' => 2, 'unit_price_minor' => 50000]], 'delivery_charge_minor' => 6000])->assertRedirect();
        return (int) DB::table('sales_orders')->max('id');
    }
    private function pack(int $oid): void {
        $this->postJson($this->base.'/courier/pack', ['order_id' => $oid, 'scans' => [['product_id' => $this->pid, 'quantity' => 2]]])->assertOk();
    }

    public function test_steadfast_booking_label_scan_handover_webhook_and_sync(): void {
        Http::fake([
            'portal.packzy.com/api/v1/create_order' => Http::sequence()
                ->push(['status' => 200, 'message' => 'ok', 'consignment' => ['consignment_id' => 1424107, 'tracking_code' => '15BAEB8A', 'status' => 'in_review']])
                ->push(['status' => 400, 'errors' => ['recipient_phone' => ['The recipient phone must be 11 digits.']]], 422),
            'portal.packzy.com/api/v1/get_balance' => ['status' => 200, 'current_balance' => 1520.5],
            'portal.packzy.com/api/v1/status_by_cid/*' => ['status' => 200, 'delivery_status' => 'delivered'],
        ]);
        $a = $this->order('01711000000'); $b = $this->order('01811000000');
        $this->post_("/orders/$a/payments", ['method' => 'bkash', 'amount_minor' => 10000])->assertSessionHasNoErrors();
        // Not configured -> clear error.
        $this->postJson($this->base.'/courier/book', ['courier' => 'steadfast', 'order_ids' => [$a]])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'switched off'));
        $this->post_('/settings', ['values' => ['courier.steadfast.enabled' => '1'], 'secrets' => ['courier.steadfast.api_key' => 'AK', 'courier.steadfast.secret_key' => 'SK']]);
        $this->postJson($this->base.'/settings/courier-test', ['courier' => 'steadfast'])->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, '1,520.50'));

        $res = $this->postJson($this->base.'/courier/book', ['courier' => 'steadfast', 'order_ids' => [$a, $b]])->assertOk()->json('results');
        $this->assertTrue($res[0]['ok']); $this->assertSame('1424107', $res[0]['consignment_id']);
        $this->assertFalse($res[1]['ok']); $this->assertStringContainsString('11 digits', $res[1]['error']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/create_order') && $r['invoice'] === (string) $a && $r['cod_amount'] == 960
            && $r->header('Api-Key')[0] === 'AK' && $r['recipient_phone'] === '01711000000');
        // Booking again is a no-op; bookable list excludes it.
        $this->assertTrue($this->postJson($this->base.'/courier/book', ['courier' => 'steadfast', 'order_ids' => [$a]])->json('results.0.already'));
        $this->assertSame([$b], array_column($this->getJson($this->base.'/courier/bookable')->json(), 'id'));

        // Handover by scanning the courier label (tracking code) -> stored as consignment id + code.
        $this->pack($a);
        $this->get($this->base.'/courier/dispatch')->assertOk()->assertInertia(fn ($p) => $p->where("bookings.$a.consignment_id", '1424107')->where('apiCouriers', ['steadfast']));
        $this->post_('/courier/dispatch', ['idempotency_key' => (string) Str::uuid(), 'courier' => 'steadfast', 'parcels' => [['order_id' => $a, 'tracking_id' => '15BAEB8A']]])->assertSessionHasNoErrors();
        $s = DB::table('shipments')->where('sales_order_id', $a)->first();
        $this->assertSame(['1424107', '15BAEB8A', 96000], [$s->tracking_id, $s->alt_tracking, (int) $s->cod_expected_minor]);
        $this->assertSame('handed_over', DB::table('courier_bookings')->where('sales_order_id', $a)->value('status'));
        $this->getJson($this->base.'/courier/parcel?tracking=15BAEB8A&courier=steadfast')->assertOk()->assertJsonPath('order_id', $a);

        // Webhook: wrong token rejected; partial -> recorded only; delivered -> closes parcel.
        $token = $this->get($this->base.'/settings')->viewData('page')['props']['steadfastToken'];
        $this->postJson($this->base.'/api/hooks/steadfast', ['consignment_id' => 1424107, 'status' => 'delivered'], ['Authorization' => 'Bearer nope'])->assertStatus(401);
        $this->postJson($this->base.'/api/hooks/steadfast', ['consignment_id' => 1424107, 'invoice' => (string) $a, 'status' => 'hold', 'cod_amount' => 960, 'updated_at' => now()->toIso8601String()],
            ['Authorization' => "Bearer $token"])->assertOk();
        $this->assertSame(['shipped', 'hold'], [DB::table('sales_orders')->where('id', $a)->value('status'), DB::table('shipments')->where('id', $s->id)->value('courier_status')]);
        $this->postJson($this->base.'/api/hooks/steadfast', ['consignment_id' => 1424107, 'invoice' => (string) $a, 'status' => 'delivered', 'cod_amount' => 960, 'updated_at' => now()->toIso8601String()],
            ['Authorization' => "Bearer $token"])->assertOk();
        $this->assertSame('delivered', DB::table('sales_orders')->where('id', $a)->value('status'));
        $this->assertSame(0, (int) DB::table('finished_good_stocks')->value('transit_stock'));
    }

    public function test_pathao_and_redx_booking_and_status_sync(): void {
        Http::fake([
            'api-hermes.pathao.com/aladdin/api/v1/issue-token' => ['token_type' => 'Bearer', 'expires_in' => 432000, 'access_token' => 'PT-TOKEN'],
            'api-hermes.pathao.com/aladdin/api/v1/orders' => ['type' => 'success', 'code' => 200, 'data' => ['consignment_id' => 'DT0101ABC', 'order_status' => 'Pending', 'delivery_fee' => 60]],
            'api-hermes.pathao.com/aladdin/api/v1/orders/*/info' => ['type' => 'success', 'data' => ['consignment_id' => 'DT0101ABC', 'order_status' => 'Delivered']],
            'openapi.redx.com.bd/v1.0.0-beta/areas' => ['areas' => [['id' => 1, 'name' => 'Mirpur', 'district_name' => 'Dhaka'], ['id' => 2, 'name' => 'Mirpur 10', 'district_name' => 'Dhaka'], ['id' => 3, 'name' => 'Agrabad', 'district_name' => 'Chattogram']]],
            'openapi.redx.com.bd/v1.0.0-beta/parcel' => ['tracking_id' => '23A1B2C3'],
            'openapi.redx.com.bd/v1.0.0-beta/parcel/info/*' => ['parcel' => ['tracking_id' => '23A1B2C3', 'status' => 'agent-returning']],
        ]);
        $this->post_('/settings', ['values' => ['courier.pathao.enabled' => '1', 'courier.pathao.client_id' => 'CID', 'courier.pathao.username' => 'm@x.com', 'courier.pathao.store_id' => '12345',
            'courier.redx.enabled' => '1'], 'secrets' => ['courier.pathao.client_secret' => 'CS', 'courier.pathao.password' => 'PW', 'courier.redx.token' => 'RX']]);
        $p = $this->order('01711000000'); $r = $this->order('01811000000', 'Flat 3B, Road 2, Mirpur 10, Dhaka'); $bad = $this->order('01911000000', 'Somewhere unknown');
        $this->assertTrue($this->postJson($this->base.'/courier/book', ['courier' => 'pathao', 'order_ids' => [$p]])->json('results.0.ok'));
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/orders') && $req->hasHeader('Authorization', 'Bearer PT-TOKEN') && $req['store_id'] === 12345 && $req['amount_to_collect'] === 1060);
        $rx = $this->postJson($this->base.'/courier/book', ['courier' => 'redx', 'order_ids' => [$r, $bad]])->json('results');
        $this->assertTrue($rx[0]['ok']); $this->assertFalse($rx[1]['ok']); $this->assertStringContainsString('area', $rx[1]['error']);
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/parcel') && $req['delivery_area_id'] === 2 && $req->hasHeader('API-ACCESS-TOKEN', 'Bearer RX'));

        foreach ([[$p, 'pathao', 'DT0101ABC'], [$r, 'redx', '23A1B2C3']] as [$oid, $c, $t]) {
            $this->pack($oid);
            $this->post_('/courier/dispatch', ['idempotency_key' => (string) Str::uuid(), 'courier' => $c, 'parcels' => [['order_id' => $oid, 'tracking_id' => $t]]])->assertSessionHasNoErrors();
        }
        $this->post_('/courier/sync')->assertSessionHas('success', fn ($m) => str_contains($m, 'Checked 2') && str_contains($m, '1 delivered'));
        $this->assertSame(['delivered', 'shipped'], [DB::table('sales_orders')->where('id', $p)->value('status'), DB::table('sales_orders')->where('id', $r)->value('status')]);
        $this->assertSame('agent-returning', DB::table('shipments')->where('sales_order_id', $r)->value('courier_status'));
        // Scheduled sync honours the per-company switch.
        $this->post_('/settings', ['values' => ['courier.auto_sync' => '1']]);
        $this->artisan('opryx:courier-sync')->assertSuccessful();
    }
}
