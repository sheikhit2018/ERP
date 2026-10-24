<?php
namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\{LedgerEntry, MoneyAccount, User};
use App\Services\{Accounts, ReportService};
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/** Accounts: cash book, income & expenses, transfers, profit & loss. */
class AccountsController extends Controller {
    public function index(Request $r) {
        Accounts::defaults();
        $tab = in_array($r->query('tab'), ['book', 'pl', 'accounts'], true) ? $r->query('tab') : 'book';
        $from = Carbon::parse($r->query('from') ?: now()->startOfMonth()->toDateString())->startOfDay();
        $to = Carbon::parse($r->query('to') ?: now()->toDateString())->endOfDay();
        $acc = $r->integer('account') ?: null; $cat = (string) $r->query('category', '');
        $entries = LedgerEntry::with('account:id,name')->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->when($acc, fn ($q) => $q->where('money_account_id', $acc))->when($cat !== '', fn ($q) => $q->where('category', $cat))
            ->orderByDesc('entry_date')->orderByDesc('id')->paginate(100)->withQueryString();
        $sum = LedgerEntry::whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])->when($acc, fn ($q) => $q->where('money_account_id', $acc))
            ->where('category', '!=', 'transfer')->selectRaw("coalesce(sum(case when direction='in' then amount_minor else 0 end),0) as inn, coalesce(sum(case when direction='out' then amount_minor else 0 end),0) as out")->first();
        return Inertia::render('Accounts/Index', [
            'tab' => $tab, 'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'account' => $acc, 'category' => $cat],
            'accounts' => Accounts::balances(), 'entries' => $entries, 'totals' => ['in' => (int) $sum->inn, 'out' => (int) $sum->out],
            'expenseCats' => Accounts::EXPENSES, 'incomeCats' => Accounts::INCOMES, 'pl' => $tab === 'pl' ? $this->pl($from, $to) : null,
            'users' => User::whereIn('id', $entries->getCollection()->pluck('created_by')->unique())->pluck('name', 'id'),
        ]);
    }

    /** Profit & loss: sales profit (delivered parcels) − business expenses + other income. */
    private function pl(Carbon $from, Carbon $to): array {
        $profit = (new ReportService($from, $to))->profit();
        $rows = LedgerEntry::whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])->whereNotIn('category', Accounts::NON_PL)
            ->groupBy('direction', 'category')->selectRaw('direction, category, sum(amount_minor) as total')->get();
        $exp = $rows->where('direction', 'out')->map(fn ($x) => ['category' => $x->category, 'label' => Accounts::EXPENSES[$x->category] ?? $x->category, 'total' => (int) $x->total])->sortByDesc('total')->values();
        $inc = $rows->where('direction', 'in')->map(fn ($x) => ['category' => $x->category, 'label' => Accounts::INCOMES[$x->category] ?? $x->category, 'total' => (int) $x->total])->values();
        $net = $profit['net_after_losses'] + $inc->sum('total') - $exp->sum('total');
        return ['profit' => $profit, 'expenses' => $exp, 'income' => $inc, 'expense_total' => $exp->sum('total'), 'income_total' => $inc->sum('total'), 'net' => $net];
    }

    public function store(Request $r) {
        Accounts::record($r->all(), (int) $r->user()->id);
        return back()->with('success', 'Saved to the cash book.');
    }
    public function transfer(Request $r) {
        $d = $r->validate(['from' => 'required|integer', 'to' => 'required|integer', 'amount_minor' => 'required|integer|min:1', 'entry_date' => 'required|date', 'note' => 'nullable|string|max:300']);
        Accounts::transfer($d['from'], $d['to'], $d['amount_minor'], $d['entry_date'], $d['note'] ?? null, (int) $r->user()->id);
        return back()->with('success', 'Transfer saved.');
    }
    public function destroy(int $entry) {
        $e = LedgerEntry::findOrFail($entry);
        abort_if($e->source_type !== null, 422, 'Automatic entries are removed from where they were created (order, purchase, payroll).');
        $e->transfer_group ? LedgerEntry::where('transfer_group', $e->transfer_group)->delete() : $e->delete();
        return back()->with('success', 'Entry deleted.');
    }
    public function saveAccount(Request $r) {
        $d = $r->validate(['id' => 'nullable|integer', 'name' => 'required|string|max:80', 'type' => 'required|in:cash,bank,mobile', 'opening_minor' => 'required|integer', 'active' => 'boolean']);
        $a = !empty($d['id']) ? MoneyAccount::findOrFail($d['id']) : new MoneyAccount();
        $a->fill(['name' => $d['name'], 'type' => $d['type'], 'opening_minor' => $d['opening_minor'], 'active' => $d['active'] ?? true])->save();
        return back()->with('success', 'Account saved.');
    }
}
