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
}
