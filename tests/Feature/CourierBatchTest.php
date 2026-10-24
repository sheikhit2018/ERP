<?php
namespace Tests\Feature;

use App\Models\User;
use App\Support\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Bulk courier handover -> delivered -> bulk return -> lost, and the reconciliation numbers. */
class CourierBatchTest extends TestCase {
    use RefreshDatabase;

    private string $base = 'http://erp.test';

    protected function setUp(): void {
        parent::setUp();
        $this->withoutVite();
        TenantProvisioner::create('Sheikh IT', 'erp.test', 'Owner', 'owner@example.com', 'secret-pass');
        $this->actingAs(User::where('email', 'owner@example.com')->firstOrFail());
    }
    private function post_(string $p, array $d = []) { return $this->post($this->base.$p, $d); }
    private function get_(string $p) { return $this->get($this->base.$p); }
    private function stock(int $pid, string $b): int { return (int) DB::table('finished_good_stocks')->where('product_id', $pid)->value($b); }

    /** Packing station: scan every piece (exact match) -> status packed. */
    private function pack(int $oid): void {
        $scans = DB::table('sales_order_items')->where('sales_order_id', $oid)->get()->map(fn ($i) => ['product_id' => (int) $i->product_id, 'quantity' => (int) $i->quantity])->all();
        $this->postJson($this->base.'/courier/pack', ['order_id' => $oid, 'scans' => $scans])->assertOk()->assertJsonPath('status', 'packed');
    }

    /** @return int[] order ids; order i has (i % 3) + 1 pieces (1, 2 or 3 pcs per parcel) */
    private function orders(int $pid, int $n): array {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $this->post_('/orders', ['idempotency_key' => (string) Str::uuid(), 'status' => 'confirmed',
                'customer' => ['name' => "C$i", 'phone' => '0181111'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'address' => 'Dhaka'],
                'items' => [['product_id' => $pid, 'quantity' => $i % 3 + 1, 'unit_price_minor' => 50000]]])->assertRedirect();
            $ids[] = (int) DB::table('sales_orders')->max('id');
        }
        return $ids;
    }

    public function test_bulk_handover_delivered_returns_lost_and_reconciliation(): void {
        $this->post_('/products', ['code' => 'SH', 'name' => 'Shirt', 'price_minor' => 50000, 'colors' => ['White'], 'sizes' => ['L'],
            'quantities' => [['color' => 'White', 'size' => 'L', 'quantity' => 1000]]])->assertSessionHasNoErrors();
        $pid = (int) DB::table('products')->value('id');
        $ids = $this->orders($pid, 30); // 10 parcels each of 1, 2, 3 pcs = 60 pcs
        $this->assertSame(940, $this->stock($pid, 'available_stock'));
        // Not packed yet -> bulk handover refuses; wrong piece count at packing is refused.
        $this->post_('/courier/dispatch', ['idempotency_key' => (string) Str::uuid(), 'courier' => 'steadfast', 'parcels' => [['order_id' => $ids[0], 'tracking_id' => 'X1']]])
            ->assertSessionHasErrors('inventory');
        $this->postJson($this->base.'/courier/pack', ['order_id' => $ids[1], 'scans' => [['product_id' => $pid, 'quantity' => 1]]])->assertUnprocessable();
        foreach ($ids as $id) $this->pack($id);
        $this->get_('/courier/pack')->assertOk();
        $this->getJson($this->base.'/courier/pack/order?order=%23'.$ids[0])->assertOk()->assertJsonPath('status', 'packed');
        $this->get_('/courier/dispatch')->assertOk()->assertInertia(fn ($p) => $p->component('Courier/Dispatch')->has('orders', 30));

        // 1. Bulk handover: 30 parcels / 60 pcs, recorded on one batch.
        $key = (string) Str::uuid();
        $parcels = array_map(fn ($id) => ['order_id' => $id, 'tracking_id' => "SF$id"], $ids);
        $bad = $parcels; $bad[1]['tracking_id'] = $bad[0]['tracking_id'];
        $this->post_('/courier/dispatch', ['idempotency_key' => (string) Str::uuid(), 'courier' => 'steadfast', 'parcels' => $bad])->assertSessionHasErrors('inventory');
        $this->post_('/courier/dispatch', ['idempotency_key' => $key, 'courier' => 'steadfast', 'parcels' => $parcels])->assertSessionHasNoErrors()->assertRedirect();
        $this->post_('/courier/dispatch', ['idempotency_key' => $key, 'courier' => 'steadfast', 'parcels' => $parcels])->assertSessionHasNoErrors(); // replay is safe
        $batch = DB::table('courier_batches')->where('type', 'dispatch')->first();
        $this->assertSame([30, 60], [(int) $batch->parcel_count, (int) $batch->piece_count]);
        $this->assertSame(1, DB::table('courier_batches')->count());
        $this->assertSame([0, 60], [$this->stock($pid, 'reserved_stock'), $this->stock($pid, 'transit_stock')]);
        // An order cannot be handed over twice.
        $this->post_('/courier/dispatch', ['idempotency_key' => (string) Str::uuid(), 'courier' => 'pathao', 'parcels' => [['order_id' => $ids[0], 'tracking_id' => 'PT1']]])
            ->assertSessionHasErrors('inventory');

        // 2. Courier statement: 20 delivered (pasted tracking IDs, plus junk + a repeat).
        $deliv = array_map(fn ($id) => "SF$id", array_slice($ids, 0, 20));
        $this->post_('/courier/delivered', ['courier' => 'steadfast', 'tracking_ids' => [...$deliv, 'NOPE-1', $deliv[0]]])
            ->assertSessionHasNoErrors()->assertSessionHas('report', fn ($r) => count($r) === 1 && str_contains($r[0], 'NOPE-1'));

        // 3. Courier brings back 6 parcels: 5 good + 1 damaged, all scanned in one return batch.
        $ret = array_map(fn ($id) => ['tracking_id' => "SF$id", 'damaged' => false], array_slice($ids, 20, 6));
        $ret[5]['damaged'] = true;
        $this->getJson($this->base.'/courier/parcel?tracking=SF'.$ids[20].'&courier=steadfast')->assertOk()->assertJsonPath('status', 'shipped');
        $this->post_('/courier/returns', ['idempotency_key' => (string) Str::uuid(), 'courier' => 'steadfast', 'parcels' => [['tracking_id' => "SF{$ids[0]}"]]])
            ->assertSessionHasErrors('inventory'); // already delivered
        $this->post_('/courier/returns', ['idempotency_key' => (string) Str::uuid(), 'courier' => 'steadfast', 'parcels' => $ret])->assertSessionHasNoErrors();
        $retPieces = array_sum(array_map(fn ($id) => $id === $ids[25] ? 0 : (array_search($id, $ids) % 3 + 1), array_slice($ids, 20, 6)));
        $dmg = 25 % 3 + 1;

        // 4. Reconciliation: 30 sent - 20 delivered - 6 returned = 4 still with the courier.
        $this->travel(4)->days();
        $page = $this->get_('/courier')->assertOk();
        $t = $page->viewData('page')['props']['total'];
        $this->assertSame([30, 60, 20, 6, 4, 4, 0], [$t['sent_parcels'], $t['sent_pieces'], $t['delivered_parcels'], $t['returned_parcels'], $t['open_parcels'], $t['overdue_parcels'], $t['lost_parcels']]);
        $this->assertSame($t['sent_pieces'], $t['delivered_pieces'] + $t['returned_pieces'] + $t['open_pieces'] + $t['lost_pieces']);
        $this->assertCount(4, $page->viewData('page')['props']['overdue']);

        // 5. One overdue parcel is lost; another "lost" one turns up later in a return.
        $lost1 = DB::table('shipments')->where('tracking_id', 'SF'.$ids[26])->value('id');
        $lost2 = DB::table('shipments')->where('tracking_id', 'SF'.$ids[27])->value('id');
        $this->post_("/courier/parcels/$lost1/lost")->assertSessionHasNoErrors();
        $this->post_("/courier/parcels/$lost2/lost")->assertSessionHasNoErrors();
        $this->post_('/courier/returns', ['idempotency_key' => (string) Str::uuid(), 'courier' => 'steadfast', 'parcels' => [['tracking_id' => 'SF'.$ids[27]]]])->assertSessionHasNoErrors();
        $this->assertSame('returned', DB::table('sales_orders')->where('id', $ids[27])->value('status'));
        $this->assertSame('lost', DB::table('sales_orders')->where('id', $ids[26])->value('status'));

        $t = $this->get_('/courier')->viewData('page')['props']['total'];
        $this->assertSame([20, 7, 1, 2], [$t['delivered_parcels'], $t['returned_parcels'], $t['lost_parcels'], $t['open_parcels']]);
        // Stock adds up: transit holds exactly the open parcels.
        $this->assertSame($t['open_pieces'], $this->stock($pid, 'transit_stock'));
        $this->assertSame($dmg, $this->stock($pid, 'damaged_stock'));
        $this->assertSame(940 + $retPieces + (27 % 3 + 1), $this->stock($pid, 'available_stock'));

        $this->get_('/courier/batches/'.$batch->id)->assertOk()->assertInertia(fn ($p) => $p->has('parcels', 30)->where('stats.lost_parcels', 1));
        $this->get_('/courier/returns')->assertOk();
        $this->get_('/courier/delivered')->assertOk();
        $this->get_('/orders?status=lost')->assertOk();
    }

    public function test_itemized_returns_damaged_partial_delivery_and_missing_pieces(): void {
        $this->post_('/products', ['code' => 'TS', 'name' => 'Tee', 'price_minor' => 50000, 'colors' => ['White', 'Black'], 'sizes' => ['M', 'L'],
            'quantities' => [['color' => 'White', 'size' => 'M', 'quantity' => 100], ['color' => 'White', 'size' => 'L', 'quantity' => 100],
                ['color' => 'Black', 'size' => 'M', 'quantity' => 100], ['color' => 'Black', 'size' => 'L', 'quantity' => 100]]])->assertSessionHasNoErrors();
        $v = DB::table('products')->get()->keyBy(fn ($p) => "$p->color $p->size")->map(fn ($p) => (int) $p->id);
        $order = function (array $lines) {
            $this->post_('/orders', ['idempotency_key' => (string) Str::uuid(), 'status' => 'confirmed', 'customer' => ['name' => 'K', 'phone' => '01811111111', 'address' => 'Dhaka'],
                'items' => array_map(fn ($pid, $q) => ['product_id' => $pid, 'quantity' => $q, 'unit_price_minor' => 50000], array_keys($lines), $lines)])->assertRedirect();
            return (int) DB::table('sales_orders')->max('id');
        };
        // A: 2 White M + 1 Black L   B: 3 White L   C: 1 Black M   D: 2 Black M
        $a = $order([$v['White M'] => 2, $v['Black L'] => 1]); $b = $order([$v['White L'] => 3]); $c = $order([$v['Black M'] => 1]); $d = $order([$v['Black M'] => 2]);
        // Packing a wrong size is blocked.
        $this->postJson($this->base.'/courier/pack', ['order_id' => $b, 'scans' => [['product_id' => $v['White M'], 'quantity' => 3]]])->assertUnprocessable();
        foreach ([$a, $b, $c, $d] as $o) $this->pack($o);
        $this->post_('/courier/dispatch', ['idempotency_key' => (string) Str::uuid(), 'courier' => 'pathao',
            'parcels' => [['order_id' => $a, 'tracking_id' => 'PA'], ['order_id' => $b, 'tracking_id' => 'PB'], ['order_id' => $c, 'tracking_id' => 'PC'], ['order_id' => $d, 'tracking_id' => 'PD']]])->assertSessionHasNoErrors();
        $this->assertSame(3, $this->stock($v['Black M'], 'transit_stock'));
        $this->getJson($this->base.'/courier/parcel?tracking=PA&courier=pathao')->assertOk()->assertJsonPath('items.0.barcode', fn ($bc) => strlen($bc) === 13);

        // Product-first return scanning: which parcels with this courier still hold that colour/size?
        $find = fn ($code) => $this->getJson($this->base.'/courier/parcels/by-product?courier=pathao&code='.$code);
        $bc = fn ($k) => DB::table('products')->where('id', $v[$k])->value('barcode');
        $this->assertEqualsCanonicalizing(['PC', 'PD'], array_column($find($bc('Black M'))->assertOk()->json('parcels'), 'tracking_id'));
        $find($bc('White L'))->assertOk()->assertJsonCount(1, 'parcels')->assertJsonPath('parcels.0.order_id', $b);
        $this->getJson($this->base.'/courier/parcels/by-product?courier=steadfast&code='.$bc('White L'))->assertOk()->assertJsonCount(0, 'parcels');
        $find('NOPE')->assertNotFound();

        $ret = fn (array $parcels) => $this->post_('/courier/returns', ['idempotency_key' => (string) Str::uuid(), 'courier' => 'pathao', 'parcels' => $parcels]);
        // Short without a reason, a foreign item, or too many pieces -> whole receipt refused.
        $ret([['tracking_id' => 'PA', 'items' => [['product_id' => $v['White M'], 'returned' => 1]]]])->assertSessionHasErrors('inventory');
        $ret([['tracking_id' => 'PC', 'items' => [['product_id' => $v['White M'], 'returned' => 1], ['product_id' => $v['Black M'], 'returned' => 1]]]])->assertSessionHasErrors('inventory');
        $ret([['tracking_id' => 'PC', 'items' => [['product_id' => $v['Black M'], 'returned' => 2]]]])->assertSessionHasErrors('inventory');
        $this->assertSame(0, DB::table('courier_batches')->where('type', 'return')->count());

        // A: 2 White M back (1 damaged), Black L kept by customer.  B: 2 of 3 back, 1 missing.  C: all back.
        $ret([
            ['tracking_id' => 'PA', 'short' => 'kept', 'items' => [['product_id' => $v['White M'], 'returned' => 2, 'damaged' => 1]]],
            ['tracking_id' => 'PB', 'short' => 'missing', 'items' => [['product_id' => $v['White L'], 'returned' => 2]]],
            ['tracking_id' => 'PC', 'items' => [['product_id' => $v['Black M'], 'returned' => 1]]],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(5, (int) DB::table('courier_batches')->where('type', 'return')->value('piece_count'));
        $st = fn ($o) => DB::table('sales_orders')->where('id', $o)->value('status');
        $this->assertSame(['partial_delivered', 'returned_short', 'returned', 'shipped'], [$st($a), $st($b), $st($c), $st($d)]);
        // Stock per variant: only scanned pieces came back.
        $this->assertSame([99, 1, 0], [$this->stock($v['White M'], 'available_stock'), $this->stock($v['White M'], 'damaged_stock'), $this->stock($v['White M'], 'transit_stock')]);
        $this->assertSame([99, 0], [$this->stock($v['White L'], 'available_stock'), $this->stock($v['White L'], 'transit_stock')]);
        $this->assertSame([99, 0], [$this->stock($v['Black L'], 'available_stock'), $this->stock($v['Black L'], 'transit_stock')]);
        $this->assertSame([98, 2], [$this->stock($v['Black M'], 'available_stock'), $this->stock($v['Black M'], 'transit_stock')]);

        $t = $this->get_('/courier')->viewData('page')['props']['total'];
        $this->assertSame([4, 9], [$t['sent_parcels'], $t['sent_pieces']]);
        $this->assertSame([0, 1, 3, 5, 0, 1, 1, 2], [$t['delivered_parcels'], $t['delivered_pieces'], $t['returned_parcels'], $t['returned_pieces'],
            $t['lost_parcels'], $t['lost_pieces'], $t['open_parcels'], $t['open_pieces']]);
        $this->assertSame([1, 1, 1, 1], [$t['partial_parcels'], $t['kept_pieces'], $t['short_parcels'], $t['missing_pieces']]);
        $this->assertSame($t['sent_pieces'], $t['delivered_pieces'] + $t['returned_pieces'] + $t['lost_pieces'] + $t['open_pieces']);
        $this->assertSame(['partial_delivered', 'returned_short'], DB::table('inventory_movements')->whereIn('reason', ['partial_delivered', 'courier_short'])
            ->orderBy('id')->pluck('reason')->map(fn ($x) => $x === 'courier_short' ? 'returned_short' : $x)->all());
        $this->get_("/orders/$a")->assertOk();
        $this->get_('/orders?status=packed')->assertOk();
    }
}
