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
}
