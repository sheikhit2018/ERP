<?php
namespace App\Services;

use App\Models\{MoneyAccount, Attendance, Employee, LedgerEntry, PayrollLine, PayrollRun, SalaryAdvance, WorkLog, WorkStage};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\ValidationException;

/**
 * Attendance, production work log and monthly payroll.
 *  monthly: salary × (days in month − absent days) / days in month   (no attendance recorded = full month; leave is paid; half day = ½ absent)
 *  daily:   daily wage × (present + ½ half days)
 *  piece:   Σ pieces × stage rate (rate frozen on each work-log line)
 *  net = base + piece + bonus − deduction − advances taken this month (not yet deducted)
 */
final class Payroll {
    public const STAGES = [['Cutting', 500], ['Sewing', 2000], ['Finishing', 500], ['Ironing', 300], ['Packing', 200]];
    private static function fail(string $m): never { throw ValidationException::withMessages(['inventory' => [$m]]); }

    public static function defaults(): void {
        if (WorkStage::exists()) return;
        foreach (self::STAGES as $i => [$n, $r]) WorkStage::create(['name' => $n, 'rate_minor' => $r, 'sort' => $i]);
    }

    public static function saveStage(array $data): WorkStage {
        $d = Validator::make($data, ['id' => 'nullable|integer', 'name' => 'required|string|max:60', 'rate_minor' => 'required|integer|min:0|max:100000000', 'active' => 'boolean'])->validate();
        $st = !empty($d['id']) ? WorkStage::findOrFail($d['id']) : new WorkStage(['sort' => (int) WorkStage::max('sort') + 1]);
        $st->fill(['name' => $d['name'], 'rate_minor' => $d['rate_minor'], 'active' => $d['active'] ?? true])->save();
        return $st;
    }

    public static function saveEmployee(array $data): Employee {
        $d = Validator::make($data, ['id' => 'nullable|integer', 'name' => 'required|string|max:120', 'phone' => 'nullable|string|max:40', 'designation' => 'nullable|string|max:80',
            'department' => 'nullable|string|max:40', 'pay_type' => 'required|in:monthly,daily,piece', 'monthly_salary_minor' => 'nullable|integer|min:0', 'daily_wage_minor' => 'nullable|integer|min:0',
            'join_date' => 'nullable|date', 'active' => 'boolean'])->validate();
        $e = !empty($d['id']) ? Employee::findOrFail($d['id']) : new Employee();
        $e->fill(['name' => $d['name'], 'phone' => $d['phone'] ?? null, 'designation' => $d['designation'] ?? null, 'department' => $d['department'] ?? null, 'pay_type' => $d['pay_type'],
            'monthly_salary_minor' => (int) ($d['monthly_salary_minor'] ?? 0), 'daily_wage_minor' => (int) ($d['daily_wage_minor'] ?? 0), 'join_date' => $d['join_date'] ?? null, 'active' => $d['active'] ?? true])->save();
        return $e;
    }

    /** marks: [employee_id => present|absent|leave|half] for one date. */
    public static function attendance(string $date, array $marks): int {
        Validator::make(['date' => $date, 'marks' => $marks], ['date' => 'required|date|before_or_equal:today', 'marks' => 'required|array', 'marks.*' => 'in:present,absent,leave,half'])->validate();
        $ids = Employee::whereIn('id', array_keys($marks))->pluck('id')->all();
        $n = 0;
        foreach ($marks as $emp => $st) if (in_array((int) $emp, $ids, true)) { Attendance::updateOrCreate(['employee_id' => (int) $emp, 'work_date' => $date], ['status' => $st]); $n++; }
        return $n;
    }

    /** entries: [{employee_id, quantity}] for one date + stage (+ optional style). Rate is frozen from the stage. */
    public static function logWork(array $data, int $actor): int {
        $d = Validator::make($data, ['work_date' => 'required|date|before_or_equal:today', 'work_stage_id' => 'required|integer', 'product_style_id' => 'nullable|integer',
            'note' => 'nullable|string|max:200', 'entries' => 'required|array|min:1', 'entries.*.employee_id' => 'required|integer', 'entries.*.quantity' => 'required|integer|min:0|max:100000'])->validate();
        $stage = WorkStage::findOrFail($d['work_stage_id']);
        $ids = Employee::whereIn('id', array_column($d['entries'], 'employee_id'))->pluck('id')->all();
        $n = 0;
        DB::transaction(function () use ($d, $stage, $ids, $actor, &$n) {
            foreach ($d['entries'] as $e) {
                if ((int) $e['quantity'] === 0 || !in_array((int) $e['employee_id'], $ids, true)) continue;
                WorkLog::create(['employee_id' => (int) $e['employee_id'], 'work_stage_id' => $stage->id, 'product_style_id' => $d['product_style_id'] ?? null,
                    'work_date' => $d['work_date'], 'quantity' => (int) $e['quantity'], 'rate_minor' => (int) $stage->rate_minor, 'note' => $d['note'] ?? null, 'created_by' => $actor]);
                $n++;
            }
        });
        return $n;
    }

    public static function advance(int $employeeId, array $data, int $actor): SalaryAdvance {
        $d = Validator::make($data, ['money_account_id' => 'required|integer', 'amount_minor' => 'required|integer|min:1', 'paid_on' => 'required|date', 'note' => 'nullable|string|max:200'])->validate();
        $e = Employee::findOrFail($employeeId);
        if (!MoneyAccount::whereKey($d['money_account_id'])->exists()) self::fail('Choose an account.');
        return DB::transaction(function () use ($e, $d, $actor) {
            $a = SalaryAdvance::create(['employee_id' => $e->id, 'paid_on' => $d['paid_on'], 'amount_minor' => $d['amount_minor'], 'note' => $d['note'] ?? null, 'created_by' => $actor]);
            LedgerEntry::create(['money_account_id' => $d['money_account_id'], 'entry_date' => $d['paid_on'], 'direction' => 'out', 'amount_minor' => $d['amount_minor'], 'category' => 'salary',
                'party_type' => 'employee', 'party_id' => $e->id, 'note' => "Salary advance: {$e->name}", 'source_type' => 'salary_advance', 'source_id' => $a->id, 'created_by' => $actor]);
            return $a;
        });
    }

    /** Fresh calculation for every active employee (and anyone with work / advances in the month). */
    public static function compute(string $month): array {
        $start = Carbon::createFromFormat('Y-m-d', "$month-01")->startOfDay(); $end = $start->copy()->endOfMonth();
        $days = $start->daysInMonth;
        $att = Attendance::whereBetween('work_date', [$start->toDateString(), $end->toDateString()])->get()->groupBy('employee_id');
        $work = WorkLog::whereBetween('work_date', [$start->toDateString(), $end->toDateString()])->groupBy('employee_id')
            ->selectRaw('employee_id, sum(quantity) as q, sum(quantity * rate_minor) as amt')->get()->keyBy('employee_id');
        $adv = SalaryAdvance::whereNull('payroll_line_id')->where('paid_on', '<=', $end->toDateString())->groupBy('employee_id')->selectRaw('employee_id, sum(amount_minor) as a')->pluck('a', 'employee_id');
        $emps = Employee::where('active', true)->orWhereIn('id', $work->keys()->merge($adv->keys()))->orderBy('name')->get();
        return $emps->map(function ($e) use ($att, $work, $adv, $days) {
            $a = $att[$e->id] ?? collect();
            $absent = $a->where('status', 'absent')->count() + 0.5 * $a->where('status', 'half')->count();
            $present = $a->where('status', 'present')->count() + $a->where('status', 'leave')->count() + 0.5 * $a->where('status', 'half')->count();
            $base = match ($e->pay_type) {
                'monthly' => $a->isEmpty() ? (int) $e->monthly_salary_minor : (int) round($e->monthly_salary_minor * max(0, $days - $absent) / $days),
                'daily' => (int) round($e->daily_wage_minor * ($a->where('status', 'present')->count() + 0.5 * $a->where('status', 'half')->count())),
                default => 0,
            };
            $piece = $e->pay_type === 'piece' ? (int) ($work[$e->id]->amt ?? 0) : 0;
            $advance = (int) ($adv[$e->id] ?? 0);
            return ['employee_id' => $e->id, 'name' => $e->name, 'designation' => $e->designation, 'pay_type' => $e->pay_type, 'days_worked' => $a->isEmpty() && $e->pay_type === 'monthly' ? $days : $present,
                'days_absent' => $absent, 'pieces' => (int) ($work[$e->id]->q ?? 0), 'base_minor' => $base, 'piece_minor' => $piece, 'bonus_minor' => 0, 'deduction_minor' => 0,
                'advance_minor' => $advance, 'net_minor' => $base + $piece - $advance];
        })->values()->all();
    }

    /** Save the month's payroll (with bonus / deduction edits) and pay it from one account. */
    public static function pay(string $month, int $accountId, string $paidOn, array $adjust, int $actor): PayrollRun {
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) self::fail('Choose a month.');
        if (PayrollRun::where('month', $month)->where('status', 'paid')->exists()) self::fail("Payroll for $month is already paid.");
        if (!MoneyAccount::whereKey($accountId)->exists()) self::fail('Choose the account salaries are paid from.');
        $lines = self::compute($month);
        return DB::transaction(function () use ($month, $accountId, $paidOn, $adjust, $actor, $lines) {
            $run = PayrollRun::firstOrCreate(['month' => $month], ['status' => 'draft', 'created_by' => $actor]);
            PayrollLine::where('payroll_run_id', $run->id)->delete();
            foreach ($lines as $l) {
                $adj = $adjust[$l['employee_id']] ?? [];
                $l['bonus_minor'] = max(0, (int) ($adj['bonus_minor'] ?? 0)); $l['deduction_minor'] = max(0, (int) ($adj['deduction_minor'] ?? 0));
                $l['net_minor'] = $l['base_minor'] + $l['piece_minor'] + $l['bonus_minor'] - $l['deduction_minor'] - $l['advance_minor'];
                if ($l['base_minor'] + $l['piece_minor'] + $l['bonus_minor'] === 0 && $l['advance_minor'] === 0) continue;
                $pl = PayrollLine::create(['payroll_run_id' => $run->id] + collect($l)->except(['name', 'designation'])->all());
                SalaryAdvance::where('employee_id', $l['employee_id'])->whereNull('payroll_line_id')->where('paid_on', '<=', Carbon::parse("$month-01")->endOfMonth()->toDateString())->update(['payroll_line_id' => $pl->id]);
                if ($pl->net_minor > 0) LedgerEntry::create(['money_account_id' => $accountId, 'entry_date' => $paidOn, 'direction' => 'out', 'amount_minor' => $pl->net_minor, 'category' => 'salary',
                    'party_type' => 'employee', 'party_id' => $l['employee_id'], 'note' => "Salary $month: {$l['name']}", 'source_type' => 'payroll_line', 'source_id' => $pl->id, 'created_by' => $actor]);
            }
            $run->forceFill(['status' => 'paid', 'money_account_id' => $accountId, 'paid_on' => $paidOn])->save();
            return $run;
        });
    }
}
