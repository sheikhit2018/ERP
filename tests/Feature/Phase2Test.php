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

    public function test_hr_attendance_piece_work_advances_and_payroll(): void {
        $m = '2026-09'; // 30 days
        $this->post_('/hr/employees', ['name' => 'Rahim', 'pay_type' => 'monthly', 'monthly_salary_minor' => 3000000])->assertSessionHasNoErrors();
        $this->post_('/hr/employees', ['name' => 'Karim', 'pay_type' => 'daily', 'daily_wage_minor' => 50000])->assertSessionHasNoErrors();
        $this->post_('/hr/employees', ['name' => 'Salma', 'pay_type' => 'piece'])->assertSessionHasNoErrors();
        $this->post_('/hr/employees', ['name' => 'Office', 'pay_type' => 'monthly', 'monthly_salary_minor' => 2000000])->assertSessionHasNoErrors();
        $id = DB::table('employees')->pluck('id', 'name');
        // Rahim: 2 absent + 1 half (=2.5 absent) -> 30000 × 27.5/30 = 27500. Karim: 3 present + 1 half = 3.5 days -> 1750. Office: nothing marked -> full month.
        $this->post_('/hr/attendance', ['date' => "$m-01", 'marks' => [$id['Rahim'] => 'absent', $id['Karim'] => 'present']])->assertSessionHasNoErrors();
        $this->post_('/hr/attendance', ['date' => "$m-02", 'marks' => [$id['Rahim'] => 'absent', $id['Karim'] => 'present']]);
        $this->post_('/hr/attendance', ['date' => "$m-03", 'marks' => [$id['Rahim'] => 'half', $id['Karim'] => 'present']]);
        $this->post_('/hr/attendance', ['date' => "$m-04", 'marks' => [$id['Rahim'] => 'leave', $id['Karim'] => 'half']]);
        $this->post_('/hr/attendance', ['date' => "$m-04", 'marks' => [$id['Karim'] => 'bogus']])->assertSessionHasErrors();
        $this->post_('/hr/attendance', ['date' => now()->addDay()->toDateString(), 'marks' => [$id['Karim'] => 'present']])->assertSessionHasErrors('date');
        // Salma: 200 sewing (৳20) + 100 ironing (৳3) = 4300. Rate is frozen per entry.
        $this->props('/hr/work'); // seeds default stages
        $st = DB::table('work_stages')->pluck('id', 'name');
        $this->post_('/hr/work', ['work_date' => "$m-05", 'work_stage_id' => $st['Sewing'], 'entries' => [['employee_id' => $id['Salma'], 'quantity' => 200], ['employee_id' => $id['Karim'], 'quantity' => 0]]])->assertSessionHasNoErrors();
        $this->post_('/hr/work', ['work_date' => "$m-06", 'work_stage_id' => $st['Ironing'], 'entries' => [['employee_id' => $id['Salma'], 'quantity' => 100]]]);
        $this->post_('/hr/stages', ['id' => $st['Sewing'], 'name' => 'Sewing', 'rate_minor' => 9999])->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('work_logs')->count());
        // Advance to Salma 1000 from Cash.
        $this->props('/hr/employees');
        $this->post_("/hr/employees/{$id['Salma']}/advance", ['money_account_id' => $this->acc('Cash'), 'amount_minor' => 100000, 'paid_on' => "$m-10"])->assertSessionHasNoErrors();

        $lines = collect($this->props("/hr/payroll?month=$m")['lines'])->keyBy('name');
        $this->assertSame(2750000, $lines['Rahim']['base_minor']);
        $this->assertSame(175000, $lines['Karim']['base_minor']);
        $this->assertSame(2000000, $lines['Office']['base_minor']);
        $this->assertSame([300, 430000, 100000, 330000], [$lines['Salma']['pieces'], $lines['Salma']['piece_minor'], $lines['Salma']['advance_minor'], $lines['Salma']['net_minor']]);

        $this->post_('/hr/payroll', ['month' => $m, 'money_account_id' => $this->acc('Cash'), 'paid_on' => '2026-10-01', 'adjust' => [$id['Karim'] => ['bonus_minor' => 25000, 'deduction_minor' => 0]]])->assertSessionHasNoErrors();
        $this->post_('/hr/payroll', ['month' => $m, 'money_account_id' => $this->acc('Cash'), 'paid_on' => '2026-10-01'])->assertSessionHasErrors('inventory');
        $p = $this->props("/hr/payroll?month=$m");
        $this->assertTrue($p['paid']);
        $net = 2750000 + 200000 + 2000000 + 330000;
        $this->assertSame($net, (int) DB::table('ledger_entries')->where('source_type', 'payroll_line')->sum('amount_minor'));
        $this->assertSame(-($net + 100000), collect($this->props('/accounts')['accounts'])->firstWhere('name', 'Cash')['balance_minor']);
        $this->assertSame(0, DB::table('salary_advances')->whereNull('payroll_line_id')->count());
        // Paid month: work entries locked; salary shows as expense in P&L.
        $this->delete($this->base.'/hr/work/'.DB::table('work_logs')->value('id'))->assertSessionHas('error');
        $this->assertSame(2, DB::table('work_logs')->count());
    }
}
