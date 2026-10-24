<?php
namespace Tests\Feature;

use App\Models\User;
use App\Support\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Phase 2: accounts, purchases & suppliers, HR & payroll, production logs, locations & POS, stock audit. */
class Phase2Test extends TestCase {
    use RefreshDatabase;
    private string $base = 'http://erp.test';
    private int $pid;

    protected function setUp(): void {
        parent::setUp();
        $this->withoutVite();
        TenantProvisioner::create('Sheikh IT', 'erp.test', 'Owner', 'owner@example.com', 'secret-pass');
        $this->actingAs(User::where('email', 'owner@example.com')->firstOrFail());
        $this->post_('/products', ['code' => 'SH', 'name' => 'Shirt', 'price_minor' => 50000, 'cost_minor' => 30000, 'colors' => ['White'], 'sizes' => ['L', 'M'],
            'quantities' => [['color' => 'White', 'size' => 'L', 'quantity' => 100], ['color' => 'White', 'size' => 'M', 'quantity' => 50]]])->assertSessionHasNoErrors();
        $this->pid = (int) DB::table('products')->where('size', 'L')->value('id');
    }
    private function post_(string $p, array $d = []) { return $this->post($this->base.$p, $d); }
    private function get_(string $p) { return $this->get($this->base.$p); }
    private function props(string $p) { return $this->get_($p)->assertOk()->viewData('page')['props']; }
    private function acc(string $name): int { return (int) DB::table('money_accounts')->where('name', $name)->value('id'); }

    public function test_accounts_cash_book_auto_postings_transfer_and_profit_and_loss(): void {
        $p = $this->props('/accounts');
        $this->assertSame(['Cash', 'Bank', 'bKash', 'Nagad'], array_column($p['accounts'], 'name'));
        // Advance via bKash posts to the bKash account automatically.
        $this->post_('/orders', ['idempotency_key' => (string) Str::uuid(), 'status' => 'confirmed', 'customer' => ['name' => 'K', 'phone' => '01711000000', 'address' => 'Dhaka'],
            'items' => [['product_id' => $this->pid, 'quantity' => 2, 'unit_price_minor' => 50000]], 'delivery_charge_minor' => 6000]);
        $oid = (int) DB::table('sales_orders')->max('id');
        $this->post_("/orders/$oid/payments", ['method' => 'bkash', 'amount_minor' => 20000, 'reference' => 'BK1'])->assertSessionHasNoErrors();
        // Ship + courier statement -> net COD posted to Bank.
        $this->postJson($this->base.'/courier/pack', ['order_id' => $oid, 'scans' => [['product_id' => $this->pid, 'quantity' => 2]]])->assertOk();
        $this->post_('/courier/dispatch', ['idempotency_key' => (string) Str::uuid(), 'courier' => 'steadfast', 'parcels' => [['order_id' => $oid, 'tracking_id' => 'T1']]]);
        $this->post_('/cod/import', ['idempotency_key' => (string) Str::uuid(), 'courier' => 'steadfast', 'paid_on' => now()->toDateString(), 'mark_delivered' => true,
            'lines' => [['tracking' => 'T1', 'collected' => 86000, 'charge' => 7000]]])->assertSessionHasNoErrors();
        // Manual expense, income and a transfer.
        $this->post_('/accounts/entries', ['money_account_id' => $this->acc('Cash'), 'direction' => 'out', 'amount_minor' => 1500000, 'category' => 'rent', 'entry_date' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->post_('/accounts/entries', ['money_account_id' => $this->acc('Cash'), 'direction' => 'out', 'amount_minor' => 100, 'category' => 'capital', 'entry_date' => now()->toDateString()])->assertSessionHasErrors('category');
        $this->post_('/accounts/entries', ['money_account_id' => $this->acc('Cash'), 'direction' => 'in', 'amount_minor' => 5000000, 'category' => 'capital', 'entry_date' => now()->toDateString()]);
        $this->post_('/accounts/transfer', ['from' => $this->acc('Bank'), 'to' => $this->acc('Cash'), 'amount_minor' => 50000, 'entry_date' => now()->toDateString()])->assertSessionHasNoErrors();

        $bal = collect($this->props('/accounts')['accounts'])->pluck('balance_minor', 'name');
        $this->assertSame([5000000 - 1500000 + 50000, 79000 - 50000, 20000, 0], [$bal['Cash'], $bal['Bank'], $bal['bKash'], $bal['Nagad']]);
        // Automatic entries cannot be deleted by hand; manual ones can.
        $auto = DB::table('ledger_entries')->where('source_type', 'courier_payment')->value('id');
        $this->delete($this->base."/accounts/entries/$auto")->assertStatus(422);
        // P&L: delivered profit (1000 + 60 - 600 cost - 70 charge = 390) - rent 15000 = -14610. Capital is not income.
        $pl = $this->props('/accounts?tab=pl')['pl'];
        $this->assertSame([39000, 1500000, 0, 39000 - 1500000], [$pl['profit']['net_after_losses'], $pl['expense_total'], $pl['income_total'], $pl['net']]);
        // New account with opening balance.
        $this->post_('/accounts/accounts', ['name' => 'DBBL', 'type' => 'bank', 'opening_minor' => 100000, 'active' => true])->assertSessionHasNoErrors();
        $this->assertSame(100000, collect($this->props('/accounts')['accounts'])->firstWhere('name', 'DBBL')['balance_minor']);
    }

    public function test_suppliers_purchase_partial_receive_weighted_cost_payment_and_due(): void {
        $this->post_('/raw-materials', ['sku' => 'FAB', 'name' => 'Cotton fabric', 'base_unit' => 'cm', 'opening_stock' => 0])->assertSessionHasNoErrors();
        $mat = (int) DB::table('raw_materials')->value('id');
        $this->post_('/suppliers', ['name' => 'Rahman Textile', 'phone' => '01700000000', 'opening_due_minor' => 100000])->assertSessionHasNoErrors();
        $sup = (int) DB::table('suppliers')->value('id');
        // 100 shirts @ 200 tk (current cost 300 for 100 in stock) + 5000 cm fabric @ 1 tk.
        $this->post_('/purchases', ['supplier_id' => $sup, 'order_date' => now()->toDateString(), 'lines' => [
            ['product_id' => $this->pid, 'quantity' => 100, 'unit_cost_minor' => 20000], ['raw_material_id' => $mat, 'quantity' => 5000, 'unit_cost_minor' => 100]]])->assertRedirect();
        $po = (int) DB::table('purchase_orders')->value('id');
        $items = DB::table('purchase_items')->orderBy('id')->pluck('id')->all();
        $this->post_('/purchases', ['supplier_id' => $sup, 'order_date' => now()->toDateString(), 'lines' => [['product_id' => $this->pid, 'raw_material_id' => $mat, 'quantity' => 1, 'unit_cost_minor' => 1]]])
            ->assertSessionHasErrors('inventory');
        // Partial receive: 60 shirts + all fabric; replay is safe; over-receiving refused.
        $key = (string) Str::uuid();
        $rec = ['idempotency_key' => $key, 'received_on' => now()->toDateString(), 'lines' => [['item_id' => $items[0], 'quantity' => 60], ['item_id' => $items[1], 'quantity' => 5000]]];
        $this->post_("/purchases/$po/receive", $rec)->assertSessionHasNoErrors();
        $this->post_("/purchases/$po/receive", $rec)->assertSessionHasNoErrors();
        $this->assertSame('partial', DB::table('purchase_orders')->value('status'));
        $this->assertSame(160, (int) DB::table('finished_good_stocks')->where('product_id', $this->pid)->value('available_stock'));
        $this->assertSame(5000, (int) DB::table('raw_material_stocks')->value('quantity'));
        $this->assertSame(intdiv(30000 * 100 + 20000 * 60, 160), (int) DB::table('products')->where('id', $this->pid)->value('cost_minor')); // weighted average
        $this->post_("/purchases/$po/receive", ['idempotency_key' => (string) Str::uuid(), 'received_on' => now()->toDateString(), 'lines' => [['item_id' => $items[0], 'quantity' => 41]]])->assertSessionHasErrors('inventory');
        $this->post_("/purchases/$po/receive", ['idempotency_key' => (string) Str::uuid(), 'received_on' => now()->toDateString(), 'lines' => [['item_id' => $items[0], 'quantity' => 40]]])->assertSessionHasNoErrors();
        $this->assertSame('received', DB::table('purchase_orders')->value('status'));
        $this->post_("/purchases/$po/cancel")->assertSessionHasErrors('inventory');
        // Due = 1000 opening + 20000 (shirts) + 5000 (fabric) = 26000 tk; pay 10000 from Bank.
        $this->get_('/accounts');
        $this->post_("/suppliers/$sup/pay", ['money_account_id' => $this->acc('Bank'), 'amount_minor' => 1000000, 'entry_date' => now()->toDateString(), 'reference' => 'CHQ-1'])->assertSessionHasNoErrors();
        $p = $this->props("/suppliers/$sup");
        $this->assertSame([2500000, 1000000, 1600000], [$p['due']['received'], $p['due']['paid'], $p['due']['due']]);
        $this->assertSame(1600000, $this->props('/suppliers')['suppliers'][0]['due']);
        $this->assertSame(-1000000, collect($this->props('/accounts')['accounts'])->firstWhere('name', 'Bank')['balance_minor']);
        // Supplier payment is not an expense in P&L.
        $this->assertSame(0, $this->props('/accounts?tab=pl')['pl']['expense_total']);
        $this->get_('/purchases')->assertOk(); $this->get_('/purchases/create')->assertOk(); $this->get_("/purchases/$po")->assertOk();
    }
}
