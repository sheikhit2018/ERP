<?php
namespace App\Http\Controllers;

use App\Support\{Audit, TenantProvisioner};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Self-service sign-up on the platform host: company gets <slug>.<tenant_root> and a free trial. */
class SignupController extends Controller {
    private function guard(Request $r): void {
        $host = config('opryx.signup_host'); $root = config('opryx.tenant_root');
        abort_unless($host && $root && strtolower($r->getHost()) === strtolower($host), 404);
    }
    public function create(Request $r) {
        $this->guard($r);
        return Inertia::render('Auth/Signup', ['root' => config('opryx.tenant_root'), 'trialDays' => (int) config('opryx.trial_days'),
            'plans' => DB::table('plans')->where('active', true)->orderBy('sort')->get(['id', 'name', 'price_minor', 'features'])]);
    }
    public function store(Request $r) {
        $this->guard($r);
        $d = $r->validate(['company' => 'required|string|max:120', 'subdomain' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/',
            'not_in:www,app,api,admin,mail,ftp,erp,platform,signup,login,support,help,billing,static,cdn'], 'name' => 'required|string|max:120', 'email' => 'required|email|max:190|unique:users,email',
            'phone' => 'required|string|max:40', 'password' => ['required', 'confirmed', Password::min(8)], 'plan_id' => 'nullable|integer|exists:plans,id'], ['email.unique' => 'This email already has an Opryx login. Ask your company owner to add you, or use another email.']);
        $host = strtolower($d['subdomain']).'.'.config('opryx.tenant_root');
        try { $id = TenantProvisioner::create($d['company'], $host, $d['name'], $d['email'], $d['password']); }
        catch (\RuntimeException $e) { throw ValidationException::withMessages(['subdomain' => 'That address is taken. Try another.']); }
        $plan = $d['plan_id'] ?? DB::table('plans')->where('active', true)->orderBy('sort')->value('id');
        DB::table('tenants')->where('id', $id)->update(['plan_id' => $plan, 'status' => 'trial', 'trial_ends_at' => now()->addDays((int) config('opryx.trial_days'))->toDateString(),
            'contact_phone' => $d['phone'], 'contact_email' => strtolower($d['email'])]);
        Audit::log('signup', ['company' => $d['company'], 'host' => $host], null, $id);
        return Inertia::render('Auth/SignupDone', ['url' => "https://$host/login", 'company' => $d['company']]);
    }
}
