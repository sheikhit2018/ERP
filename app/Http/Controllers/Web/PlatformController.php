<?php
namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\{Audit, Subscription, TenantProvisioner};
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Super admin: every client company, plan, billing, domains and health — across tenants (no tenant scope here). */
class PlatformController extends Controller {
    public function index() {
        $since = now()->subDays(30);
        $orders = DB::table('sales_orders')->where('created_at', '>=', $since)->groupBy('tenant_id')->selectRaw('tenant_id, count(*) as n')->pluck('n', 'tenant_id');
        $users = DB::table('tenant_user')->where('active', true)->groupBy('tenant_id')->selectRaw('tenant_id, count(*) as n')->pluck('n', 'tenant_id');
        $seen = DB::table('audit_logs')->whereNotNull('tenant_id')->groupBy('tenant_id')->selectRaw('tenant_id, max(created_at) as t')->pluck('t', 'tenant_id');
        $domains = DB::table('tenant_domains')->orderBy('id')->get(['tenant_id', 'hostname'])->groupBy('tenant_id');
        $plans = DB::table('plans')->orderBy('sort')->get();
        $tenants = DB::table('tenants')->orderBy('id')->get()->map(function ($t) use ($orders, $users, $seen, $domains) {
            $s = Subscription::state((int) $t->id);
            return ['id' => $t->id, 'name' => $t->name, 'active' => (bool) $t->active, 'status' => $t->status, 'plan' => $s['plan']['name'] ?? null, 'until' => $s['until'], 'days_left' => $s['days_left'],
                'read_only' => $s['read_only'], 'orders_30d' => (int) ($orders[$t->id] ?? 0), 'users' => (int) ($users[$t->id] ?? 0), 'last_activity' => $seen[$t->id] ?? null,
                'domains' => ($domains[$t->id] ?? collect())->pluck('hostname'), 'contact_phone' => $t->contact_phone, 'created_at' => $t->created_at];
        });
        $mrr = (int) DB::table('tenants as t')->join('plans as p', 'p.id', '=', 't.plan_id')->where('t.status', 'active')->where('t.active', true)->sum('p.price_minor');
        return Inertia::render('Platform/Index', ['tenants' => $tenants, 'plans' => $plans, 'stats' => ['companies' => $tenants->count(), 'paying' => $tenants->where('status', 'active')->whereNotNull('plan')->count(),
            'trial' => $tenants->where('status', 'trial')->count(), 'mrr' => $mrr, 'revenue_30d' => (int) DB::table('subscription_payments')->where('paid_on', '>=', $since->toDateString())->sum('amount_minor')],
            'signup' => ['host' => config('opryx.signup_host'), 'root' => config('opryx.tenant_root')]]);
    }

    public function create(Request $r) {
        $d = $r->validate(['company' => 'required|string|max:120', 'domain' => 'required|string|max:190', 'owner_name' => 'required|string|max:120', 'owner_email' => 'required|email|max:190',
            'owner_password' => ['required', Password::min(8)], 'plan_id' => 'nullable|integer|exists:plans,id', 'trial_days' => 'nullable|integer|min:0|max:365', 'contact_phone' => 'nullable|string|max:40']);
        try { $id = TenantProvisioner::create($d['company'], $d['domain'], $d['owner_name'], $d['owner_email'], $d['owner_password']); }
        catch (\RuntimeException|\InvalidArgumentException $e) { throw ValidationException::withMessages(['domain' => $e->getMessage()]); }
        $trial = (int) ($d['trial_days'] ?? 14);
        DB::table('tenants')->where('id', $id)->update(['plan_id' => $d['plan_id'] ?? null, 'status' => !empty($d['plan_id']) && $trial > 0 ? 'trial' : 'active',
            'trial_ends_at' => !empty($d['plan_id']) && $trial > 0 ? now()->addDays($trial)->toDateString() : null, 'contact_phone' => $d['contact_phone'] ?? null, 'contact_email' => strtolower($d['owner_email'])]);
        Audit::log('platform.tenant.create', ['company' => $d['company'], 'domain' => $d['domain']], null, $id);
        return redirect("/platform/tenants/$id")->with('success', "Company created. Point {$d['domain']} to this server, then the owner can log in.");
    }

    public function show(int $tenant) {
        $t = DB::table('tenants')->where('id', $tenant)->first(); abort_unless($t, 404);
        return Inertia::render('Platform/Tenant', ['tenant' => $t, 'state' => Subscription::state($tenant), 'plans' => DB::table('plans')->orderBy('sort')->get(),
            'domains' => DB::table('tenant_domains')->where('tenant_id', $tenant)->get(['id', 'hostname', 'verified_at']),
            'members' => DB::table('tenant_user as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.tenant_id', $tenant)->get(['u.id', 'u.name', 'u.email', 'm.role', 'm.active', 'u.two_factor_confirmed_at']),
            'payments' => DB::table('subscription_payments')->where('tenant_id', $tenant)->orderByDesc('id')->get(),
            'usage' => ['orders_month' => DB::table('sales_orders')->where('tenant_id', $tenant)->where('created_at', '>=', now()->startOfMonth())->count(),
                'orders_total' => DB::table('sales_orders')->where('tenant_id', $tenant)->count(), 'products' => DB::table('products')->where('tenant_id', $tenant)->count(),
                'locations' => DB::table('locations')->where('tenant_id', $tenant)->where('is_main', false)->where('active', true)->count()],
            'activity' => DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->where('a.tenant_id', $tenant)->orderByDesc('a.id')->limit(30)->get(['a.action', 'a.created_at', 'a.ip', 'u.name'])]);
    }

    public function update(Request $r, int $tenant) {
        abort_unless(DB::table('tenants')->where('id', $tenant)->exists(), 404);
        $d = $r->validate(['name' => 'required|string|max:120', 'plan_id' => 'nullable|integer|exists:plans,id', 'status' => 'required|in:trial,active,suspended',
            'trial_ends_at' => 'nullable|date', 'paid_until' => 'nullable|date', 'contact_phone' => 'nullable|string|max:40', 'contact_email' => 'nullable|email|max:120', 'note' => 'nullable|string|max:300']);
        if ((int) DB::table('tenants')->orderBy('id')->value('id') === $tenant && $d['status'] === 'suspended') throw ValidationException::withMessages(['status' => 'The platform company itself cannot be suspended.']);
        DB::table('tenants')->where('id', $tenant)->update($d + ['active' => true, 'updated_at' => now()]);
        Audit::log('platform.tenant.update', ['status' => $d['status'], 'plan_id' => $d['plan_id']], null, $tenant);
        return back()->with('success', 'Company updated.');
    }

    /** Record a subscription payment: extends paid-until by N months from today or the current end, whichever is later. */
    public function payment(Request $r, int $tenant) {
        $t = DB::table('tenants')->where('id', $tenant)->first(); abort_unless($t, 404);
        $d = $r->validate(['amount_minor' => 'required|integer|min:0', 'months' => 'required|integer|min:1|max:36', 'method' => 'required|in:bkash,nagad,bank,cash,card,other',
            'reference' => 'nullable|string|max:120', 'paid_on' => 'required|date', 'note' => 'nullable|string|max:200']);
        $from = $t->paid_until && Carbon::parse($t->paid_until)->isFuture() ? Carbon::parse($t->paid_until) : now()->startOfDay();
        $end = $from->copy()->addMonthsNoOverflow($d['months'])->toDateString();
        DB::transaction(function () use ($tenant, $d, $end, $r) {
            DB::table('subscription_payments')->insert($d + ['tenant_id' => $tenant, 'period_end' => $end, 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('tenants')->where('id', $tenant)->update(['paid_until' => $end, 'status' => 'active', 'active' => true, 'updated_at' => now()]);
        });
        Audit::log('platform.payment', ['amount_minor' => $d['amount_minor'], 'months' => $d['months']], null, $tenant);
        return back()->with('success', "Payment saved. Paid until $end.");
    }

    public function domain(Request $r, int $tenant) {
        abort_unless(DB::table('tenants')->where('id', $tenant)->exists(), 404);
        if ($r->filled('remove')) {
            if (DB::table('tenant_domains')->where('tenant_id', $tenant)->count() <= 1) throw ValidationException::withMessages(['hostname' => 'A company needs at least one domain.']);
            DB::table('tenant_domains')->where('tenant_id', $tenant)->where('id', (int) $r->input('remove'))->delete();
            return back()->with('success', 'Domain removed.');
        }
        $host = $r->validate(['hostname' => 'required|string|max:190'])['hostname'];
        try { $host = TenantProvisioner::normalizeHost($host); } catch (\InvalidArgumentException $e) { throw ValidationException::withMessages(['hostname' => $e->getMessage()]); }
        if (DB::table('tenant_domains')->where('hostname', $host)->exists()) throw ValidationException::withMessages(['hostname' => 'That domain is already used.']);
        TenantProvisioner::addDomain($tenant, $host);
        return back()->with('success', "Domain $host added. Point its DNS to this server.");
    }

    public function savePlan(Request $r) {
        $d = $r->validate(['id' => 'nullable|integer|exists:plans,id', 'name' => 'required|string|max:60', 'price_minor' => 'required|integer|min:0', 'users_limit' => 'nullable|integer|min:1',
            'orders_limit' => 'nullable|integer|min:1', 'locations_limit' => 'nullable|integer|min:0', 'features' => 'nullable|string|max:300', 'sort' => 'nullable|integer', 'active' => 'boolean']);
        $row = collect($d)->except('id')->all() + ['updated_at' => now()];
        empty($d['id']) ? DB::table('plans')->insert($row + ['created_at' => now()]) : DB::table('plans')->where('id', $d['id'])->update($row);
        return back()->with('success', 'Plan saved.');
    }
}
