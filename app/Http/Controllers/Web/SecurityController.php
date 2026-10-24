<?php
namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\{Audit, Subscription, Totp};
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, Hash, Schema};
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Billing view for the company, activity log, two-factor login and full data export. */
class SecurityController extends Controller {
    public function billing(Request $r) {
        $t = app(TenantContext::class)->id();
        return Inertia::render('Company/Billing', ['state' => Subscription::state($t),
            'usage' => ['users' => DB::table('tenant_user')->where('tenant_id', $t)->where('active', true)->count(),
                'orders' => DB::table('sales_orders')->where('tenant_id', $t)->where('created_at', '>=', now()->startOfMonth())->count(),
                'locations' => Schema::hasTable('locations') ? DB::table('locations')->where('tenant_id', $t)->where('is_main', false)->where('active', true)->count() : 0],
            'plans' => DB::table('plans')->where('active', true)->orderBy('sort')->get(['id', 'name', 'price_minor', 'users_limit', 'orders_limit', 'locations_limit', 'features']),
            'payments' => DB::table('subscription_payments')->where('tenant_id', $t)->orderByDesc('id')->limit(24)->get(['amount_minor', 'months', 'method', 'reference', 'paid_on', 'period_end']),
            'note' => config('opryx.billing_note')]);
    }

    public function audit(Request $r) {
        $t = app(TenantContext::class)->id();
        $q = DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->where('a.tenant_id', $t)
            ->when($r->query('user'), fn ($q, $u) => $q->where('a.user_id', (int) $u))
            ->when($r->query('q'), fn ($q, $s) => $q->where('a.action', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $s).'%'))
            ->orderByDesc('a.id')->select('a.id', 'a.action', 'a.method', 'a.path', 'a.status', 'a.ip', 'a.agent', 'a.meta', 'a.created_at', 'u.name as user');
        return Inertia::render('Company/Audit', ['logs' => $q->paginate(50)->withQueryString(), 'filters' => $r->only('user', 'q'),
            'users' => DB::table('tenant_user as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.tenant_id', $t)->orderBy('u.name')->get(['u.id', 'u.name'])]);
    }

    /** Owner: download every record of this company as one JSON file per table inside a ZIP (or a single JSON when ZIP is unavailable). */
    public function export(Request $r) {
        $t = app(TenantContext::class)->id();
        $skip = ['tenant_domains', 'tenant_settings'];
        $tables = collect(Schema::getTableListing())->map(fn ($n) => str_contains($n, '.') ? substr($n, strrpos($n, '.') + 1) : $n)
            ->filter(fn ($n) => !in_array($n, $skip, true) && Schema::hasColumn($n, 'tenant_id'))->values();
        Audit::log('export.download', ['tables' => $tables->count()]);
        $name = 'opryx-export-'.now()->format('Ymd-His');
        $dump = function (string $table) use ($t) { $rows = []; DB::table($table)->where('tenant_id', $t)->orderBy('id')->chunk(2000, function ($c) use (&$rows) { foreach ($c as $row) $rows[] = $row; }); return $rows; };
        if (class_exists(\ZipArchive::class)) {
            $path = tempnam(sys_get_temp_dir(), 'opx');
            $zip = new \ZipArchive(); $zip->open($path, \ZipArchive::OVERWRITE);
            foreach ($tables as $table) $zip->addFromString("$table.json", json_encode($dump($table), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $zip->addFromString('README.txt', "Opryx data export\nCompany: ".($r->attributes->get('tenant')->name ?? '')."\nCreated: ".now()."\nOne JSON file per table. Amounts ending in _minor are in poisha (÷100 = Taka).\n");
            $zip->close();
            return response()->download($path, "$name.zip", ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
        }
        $all = []; foreach ($tables as $table) $all[$table] = $dump($table);
        return response()->streamDownload(fn () => print(json_encode($all, JSON_UNESCAPED_UNICODE)), "$name.json", ['Content-Type' => 'application/json']);
    }

    // ── Two-factor login ────────────────────────────────────────────────
    public function twoFactor(Request $r) {
        $u = $r->user();
        $pending = $r->session()->get('2fa.setup');
        return Inertia::render('Profile/TwoFactor', ['enabled' => $u->hasTwoFactor(),
            'setup' => $pending ? ['secret' => $pending, 'uri' => Totp::uri($pending, $u->email, 'Opryx · '.($r->attributes->get('tenant')->name ?? 'ERP'))] : null,
            'recovery' => $r->session()->get('2fa.codes')]);
    }
    public function twoFactorStart(Request $r) {
        $r->validate(['password' => 'required|current_password']);
        $r->session()->put('2fa.setup', Totp::secret());
        return back();
    }
    public function twoFactorConfirm(Request $r) {
        $secret = $r->session()->get('2fa.setup');
        $code = (string) $r->validate(['code' => 'required|string|max:10'])['code'];
        if (!$secret || !Totp::verify($secret, $code)) throw ValidationException::withMessages(['code' => 'That code is not correct. Check the time on your phone and try again.']);
        $codes = Totp::recoveryCodes();
        $r->user()->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes), 'two_factor_confirmed_at' => now()])->save();
        $r->session()->forget('2fa.setup');
        Audit::log('security.2fa.enabled');
        return back()->with('success', 'Two-factor login is on. Save the recovery codes.')->with('2fa.codes', $codes);
    }
    public function twoFactorDisable(Request $r) {
        $r->validate(['password' => 'required|current_password']);
        $r->user()->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        Audit::log('security.2fa.disabled');
        return back()->with('success', 'Two-factor login turned off.');
    }

    /** Login step 2. The password step stores the user id in the session instead of logging in. */
    public function challenge(Request $r) {
        if (!$r->session()->has('2fa.user')) return redirect()->route('login');
        return Inertia::render('Auth/TwoFactorChallenge');
    }
    public function verifyChallenge(Request $r) {
        $id = $r->session()->get('2fa.user');
        if (!$id) return redirect()->route('login');
        $d = $r->validate(['code' => 'nullable|string|max:10', 'recovery_code' => 'nullable|string|max:40']);
        $key = '2fa:'.$id.':'.$r->ip();
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 6)) throw ValidationException::withMessages(['code' => 'Too many attempts. Wait a minute.']);
        $u = \App\Models\User::findOrFail($id);
        $ok = !empty($d['code']) && Totp::verify((string) $u->two_factor_secret, $d['code']);
        if (!$ok && !empty($d['recovery_code'])) {
            $codes = (array) $u->two_factor_recovery_codes;
            foreach ($codes as $i => $h) if (Hash::check(strtolower(trim($d['recovery_code'])), $h)) { unset($codes[$i]); $u->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save(); $ok = true; break; }
        }
        if (!$ok) { \Illuminate\Support\Facades\RateLimiter::hit($key, 60); throw ValidationException::withMessages(['code' => 'Wrong code.']); }
        \Illuminate\Support\Facades\RateLimiter::clear($key);
        $remember = (bool) $r->session()->pull('2fa.remember', false);
        $r->session()->forget('2fa.user');
        Auth::login($u, $remember);
        $r->session()->regenerate();
        Audit::log('auth.login', ['2fa' => true], $u->id, $r->attributes->get('tenant')->id ?? null);
        return redirect()->intended(route('dashboard', absolute: false));
    }

    public function locale(Request $r) {
        $l = $r->validate(['locale' => 'required|in:en,bn'])['locale'];
        $r->user()->forceFill(['locale' => $l])->save();
        return back();
    }
}
