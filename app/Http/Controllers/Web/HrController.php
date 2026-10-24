<?php
namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\{Attendance, Employee, MoneyAccount, PayrollRun, ProductStyle, SalaryAdvance, WorkLog, WorkStage};
use App\Services\{Accounts, Payroll};
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Staff, daily attendance, production work log (piece-rate) and monthly payroll. */
class HrController extends Controller {
    private function month(Request $r): string { $m = (string) $r->query('month', now()->format('Y-m')); return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? $m : now()->format('Y-m'); }
    private function date(Request $r): string { $d = (string) $r->query('date', now()->toDateString()); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) && $d <= now()->toDateString() ? $d : now()->toDateString(); }

    public function employees() {
        Accounts::defaults();
        $adv = SalaryAdvance::whereNull('payroll_line_id')->groupBy('employee_id')->selectRaw('employee_id, sum(amount_minor) as a')->pluck('a', 'employee_id');
        return Inertia::render('Hr/Employees', [
            'employees' => Employee::orderByDesc('active')->orderBy('name')->get()->map(fn ($e) => $e->only('id', 'name', 'phone', 'designation', 'department', 'pay_type', 'monthly_salary_minor', 'daily_wage_minor', 'active')
                + ['join_date' => $e->join_date?->toDateString(), 'advance_due' => (int) ($adv[$e->id] ?? 0)]),
            'accounts' => MoneyAccount::where('active', true)->get(['id', 'name']),
        ]);
    }
    public function saveEmployee(Request $r) { Payroll::saveEmployee($r->all()); return back()->with('success', 'Employee saved.'); }
    public function advance(Request $r, int $employee) { Payroll::advance($employee, $r->all(), (int) $r->user()->id); return back()->with('success', 'Advance paid and recorded in the cash book. It is deducted from the next salary.'); }

    public function attendance(Request $r) {
        $date = $this->date($r);
        return Inertia::render('Hr/Attendance', ['date' => $date,
            'employees' => Employee::where('active', true)->orderBy('name')->get(['id', 'name', 'designation', 'pay_type']),
            'marks' => Attendance::where('work_date', $date)->pluck('status', 'employee_id'),
            'summary' => Attendance::whereBetween('work_date', [substr($date, 0, 7).'-01', $date])->groupBy('status')->selectRaw('status, count(*) as c')->pluck('c', 'status')]);
    }
    public function saveAttendance(Request $r) {
        $n = Payroll::attendance((string) $r->input('date'), (array) $r->input('marks', []));
        return back()->with('success', "Attendance saved for $n staff.");
    }

    public function work(Request $r) {
        Payroll::defaults();
        $date = $this->date($r);
        $styleNames = ProductStyle::pluck('name', 'id');
        return Inertia::render('Hr/WorkLog', ['date' => $date,
            'stages' => WorkStage::orderBy('sort')->get(['id', 'name', 'rate_minor', 'active']),
            'employees' => Employee::where('active', true)->orderBy('name')->get(['id', 'name', 'designation', 'pay_type']),
            'styles' => $styleNames->sort()->map(fn ($n, $id) => ['id' => $id, 'name' => $n])->values(),
            'logs' => WorkLog::with(['employee:id,name', 'stage:id,name'])->where('work_date', $date)->latest('id')->get()
                ->map(fn ($l) => ['id' => $l->id, 'employee' => $l->employee?->name, 'stage' => $l->stage?->name, 'quantity' => $l->quantity, 'rate_minor' => (int) $l->rate_minor, 'note' => $l->note,
                    'style' => $styleNames[$l->product_style_id] ?? null]),
            'month' => WorkLog::with('stage:id,name')->whereBetween('work_date', [substr($date, 0, 7).'-01', $date])->get()->groupBy('work_stage_id')
                ->map(fn ($g) => ['stage' => $g->first()->stage?->name, 'pieces' => (int) $g->sum('quantity'), 'amount' => (int) $g->sum(fn ($l) => $l->quantity * $l->rate_minor)])->values()]);
    }
    public function saveWork(Request $r) { $n = Payroll::logWork($r->all(), (int) $r->user()->id); return back()->with('success', "$n work entries saved."); }
    public function deleteWork(int $log) {
        $l = WorkLog::findOrFail($log);
        if (PayrollRun::where('month', $l->work_date->format('Y-m'))->where('status', 'paid')->exists()) return back()->with('error', 'That month is already paid — the entry cannot be removed.');
        $l->delete(); return back()->with('success', 'Entry removed.');
    }
    public function saveStage(Request $r) { Payroll::saveStage($r->all()); return back()->with('success', 'Work stage saved.'); }

    public function payroll(Request $r) {
        Accounts::defaults();
        $month = $this->month($r);
        $run = PayrollRun::where('month', $month)->with('lines.employee:id,name,designation')->first();
        $paid = $run && $run->status === 'paid';
        return Inertia::render('Hr/Payroll', ['month' => $month, 'paid' => $paid, 'paid_on' => $run?->paid_on?->toDateString(),
            'account' => $paid ? MoneyAccount::find($run->money_account_id)?->name : null,
            'lines' => $paid ? $run->lines->map(fn ($l) => $l->only('employee_id', 'pay_type', 'days_worked', 'days_absent', 'pieces', 'base_minor', 'piece_minor', 'bonus_minor', 'deduction_minor', 'advance_minor', 'net_minor')
                + ['name' => $l->employee?->name, 'designation' => $l->employee?->designation])->values() : Payroll::compute($month),
            'accounts' => MoneyAccount::where('active', true)->get(['id', 'name']),
            'runs' => PayrollRun::where('status', 'paid')->latest('month')->take(12)->get(['month', 'paid_on'])]);
    }
    public function pay(Request $r) {
        $d = $r->validate(['month' => 'required|string', 'money_account_id' => 'required|integer', 'paid_on' => 'required|date', 'adjust' => 'array']);
        Payroll::pay($d['month'], (int) $d['money_account_id'], $d['paid_on'], $d['adjust'] ?? [], (int) $r->user()->id);
        return back()->with('success', "Salaries for {$d['month']} paid and posted to the cash book.");
    }
}
