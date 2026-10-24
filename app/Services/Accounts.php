<?php
namespace App\Services;

use App\Models\{LedgerEntry, MoneyAccount};
use App\Support\Settings;
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Cash book: money accounts, manual income / expense / transfers, and automatic postings from other modules. */
final class Accounts {
    public const EXPENSES = ['rent' => 'Rent', 'salary' => 'Salaries & wages', 'utilities' => 'Electricity, gas, water, internet', 'marketing' => 'Marketing & ads',
        'packaging' => 'Packaging', 'transport' => 'Transport & delivery', 'courier_charge' => 'Courier charges', 'supplier_payment' => 'Supplier payments',
        'raw_material' => 'Raw material (cash buy)', 'office' => 'Office & stationery', 'maintenance' => 'Repair & maintenance', 'tax' => 'Tax & VAT',
        'bank_charge' => 'Bank / gateway charges', 'owner_draw' => 'Owner drawings', 'other_expense' => 'Other expense'];
    public const INCOMES = ['courier_cod' => 'COD from courier', 'customer_advance' => 'Customer advance / prepaid', 'pos_sale' => 'Shop (POS) sales',
        'capital' => 'Owner capital', 'loan' => 'Loan received', 'other_income' => 'Other income', 'supplier_refund' => 'Supplier refund'];
    /** Categories that are not business expenses / income in the profit statement. */
    public const NON_PL = ['supplier_payment', 'owner_draw', 'transfer', 'capital', 'loan', 'courier_cod', 'customer_advance', 'pos_sale', 'raw_material', 'courier_charge'];

    public static function defaults(): void {
        if (MoneyAccount::exists()) return;
        foreach ([['Cash', 'cash'], ['Bank', 'bank'], ['bKash', 'mobile'], ['Nagad', 'mobile']] as [$n, $t]) MoneyAccount::create(['name' => $n, 'type' => $t, 'opening_minor' => 0]);
    }

    public static function balances(): array {
        $sums = LedgerEntry::groupBy('money_account_id')->selectRaw("money_account_id, sum(case when direction = 'in' then amount_minor else -amount_minor end) as net")->pluck('net', 'money_account_id');
        return MoneyAccount::orderBy('id')->get()->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'type' => $a->type, 'active' => $a->active,
            'opening_minor' => (int) $a->opening_minor, 'balance_minor' => (int) $a->opening_minor + (int) ($sums[$a->id] ?? 0)])->all();
    }

    /** Which account receives money of this kind (Settings → Accounts, else first account of a matching type/name). */
    public static function accountFor(string $kind): ?int {
        self::defaults();
        $set = (int) Settings::get("accounts.map_$kind", 0);
        if ($set && MoneyAccount::whereKey($set)->where('active', true)->exists()) return $set;
        $byName = MoneyAccount::where('active', true)->whereRaw('lower(name) like ?', ['%'.strtolower($kind).'%'])->value('id');
        if ($byName) return (int) $byName;
        $type = match ($kind) { 'cash' => 'cash', 'bkash', 'nagad', 'rocket' => 'mobile', default => 'bank' };
        return (int) (MoneyAccount::where('active', true)->where('type', $type)->orderBy('id')->value('id') ?? MoneyAccount::where('active', true)->orderBy('id')->value('id'));
    }

    /** Automatic posting, exactly once per source. Silent if amount is zero. */
    public static function auto(string $sourceType, int $sourceId, string $kind, string $direction, int $amount, string $category, int $actor, array $extra = []): ?LedgerEntry {
        if ($amount <= 0) return null;
        $acc = self::accountFor($kind);
        if (!$acc) return null;
        $old = LedgerEntry::where('source_type', $sourceType)->where('source_id', $sourceId)->first();
        if ($old) return $old;
        return LedgerEntry::create(['money_account_id' => $acc, 'entry_date' => $extra['date'] ?? now()->toDateString(), 'direction' => $direction, 'amount_minor' => $amount,
            'category' => $category, 'party_type' => $extra['party_type'] ?? null, 'party_id' => $extra['party_id'] ?? null,
            'reference' => isset($extra['reference']) ? mb_substr((string) $extra['reference'], 0, 120) : null, 'note' => isset($extra['note']) ? mb_substr((string) $extra['note'], 0, 300) : null,
            'source_type' => $sourceType, 'source_id' => $sourceId, 'created_by' => $actor]);
    }

    /** Manual income / expense. */
    public static function record(array $data, int $actor): LedgerEntry {
        $cats = array_merge(array_keys(self::EXPENSES), array_keys(self::INCOMES));
        $d = Validator::make($data, ['money_account_id' => 'required|integer', 'direction' => 'required|in:in,out', 'amount_minor' => 'required|integer|min:1|max:100000000000',
            'category' => 'required|in:'.implode(',', $cats), 'entry_date' => 'required|date', 'reference' => 'nullable|string|max:120', 'note' => 'nullable|string|max:300',
            'party_type' => 'nullable|in:supplier,employee,courier,customer', 'party_id' => 'nullable|integer'])->validate();
        if (!MoneyAccount::whereKey($d['money_account_id'])->exists()) throw ValidationException::withMessages(['money_account_id' => 'Choose an account.']);
        if ($d['direction'] === 'out' && !isset(self::EXPENSES[$d['category']])) throw ValidationException::withMessages(['category' => 'Choose an expense category.']);
        if ($d['direction'] === 'in' && !isset(self::INCOMES[$d['category']])) throw ValidationException::withMessages(['category' => 'Choose an income category.']);
        return LedgerEntry::create($d + ['created_by' => $actor]);
    }

    public static function transfer(int $from, int $to, int $amount, string $date, ?string $note, int $actor): void {
        if ($from === $to || $amount <= 0 || !MoneyAccount::whereKey($from)->exists() || !MoneyAccount::whereKey($to)->exists())
            throw ValidationException::withMessages(['amount_minor' => 'Choose two different accounts and an amount.']);
        DB::transaction(function () use ($from, $to, $amount, $date, $note, $actor) {
            $g = (string) Str::uuid();
            foreach ([[$from, 'out'], [$to, 'in']] as [$a, $dir]) LedgerEntry::create(['money_account_id' => $a, 'entry_date' => $date, 'direction' => $dir, 'amount_minor' => $amount,
                'category' => 'transfer', 'note' => $note, 'transfer_group' => $g, 'created_by' => $actor]);
        });
    }
}
