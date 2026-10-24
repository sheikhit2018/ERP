<?php
namespace Tests\Feature;

use App\Models\User;
use App\Support\{Subscription, Totp, TenantProvisioner};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Phase 3: SaaS plans & billing, super admin, audit log, 2FA, data export, sign-up, branding, PWA. */
class Phase3Test extends TestCase {
    use RefreshDatabase;
    private string $base = 'http://erp.test';
    private User $owner;

    protected function setUp(): void {
        parent::setUp();
        $this->withoutVite();
        TenantProvisioner::create('Sheikh IT', 'erp.test', 'Owner', 'owner@example.com', 'secret-pass');
        $this->owner = User::where('email', 'owner@example.com')->firstOrFail();
        $this->owner->forceFill(['is_super_admin' => true])->save();
        $this->actingAs($this->owner);
    }
    private function props(string $p, string $host = 'erp.test') { return $this->get("http://$host$p")->assertOk()->viewData('page')['props']; }

    public function test_platform_onboarding_billing_limits_and_read_only(): void {
        $this->assertSame(3, DB::table('plans')->count());
        $p = $this->props('/platform');
        $this->assertSame(1, $p['stats']['companies']);
        $starter = (int) DB::table('plans')->where('name', 'Starter')->value('id');
        DB::table('plans')->where('id', $starter)->update(['orders_limit' => 1, 'users_limit' => 1]);
        $this->post($this->base.'/platform/tenants', ['company' => 'Client Co', 'domain' => 'client.test', 'owner_name' => 'C', 'owner_email' => 'c@client.test',
            'owner_password' => 'client-pass-1', 'plan_id' => $starter, 'trial_days' => 7])->assertSessionHasNoErrors()->assertRedirect();
        $this->post($this->base.'/platform/tenants', ['company' => 'Dup', 'domain' => 'client.test', 'owner_name' => 'C', 'owner_email' => 'd@x.test', 'owner_password' => 'client-pass-1'])->assertSessionHasErrors('domain');
        $tid = (int) DB::table('tenants')->where('name', 'Client Co')->value('id');
        $this->assertSame(['trial', now()->addDays(7)->toDateString()], [DB::table('tenants')->where('id', $tid)->value('status'), substr((string) DB::table('tenants')->where('id', $tid)->value('trial_ends_at'), 0, 10)]);
        $this->props("/platform/tenants/$tid");

        // Client owner: limits enforced.
        $client = User::where('email', 'c@client.test')->first();
        $this->actingAs($client);
        $this->get($this->base.'/platform')->assertForbidden();
        $this->get('http://client.test/platform')->assertForbidden();
        $this->post('http://client.test/team', ['name' => 'X', 'email' => 'x@client.test', 'password' => 'xxxxxxxx', 'role' => 'sales'])->assertSessionHasErrors('inventory');
        $this->post('http://client.test/products', ['code' => 'TS', 'name' => 'Tee', 'price_minor' => 100, 'cost_minor' => 50, 'colors' => ['Red'], 'sizes' => ['M'], 'quantities' => [['color' => 'Red', 'size' => 'M', 'quantity' => 10]]])->assertSessionHasNoErrors();
        $pid = (int) DB::table('products')->where('tenant_id', $tid)->value('id');
        $order = fn () => $this->post('http://client.test/orders', ['idempotency_key' => (string) Str::uuid(), 'status' => 'confirmed', 'customer' => ['name' => 'K', 'phone' => '01711000000', 'address' => 'D'],
            'items' => [['product_id' => $pid, 'quantity' => 1, 'unit_price_minor' => 100]]]);
        $order()->assertSessionHasNoErrors();
        $order()->assertSessionHasErrors('inventory');
        $this->assertSame(1, $this->props('/billing', 'client.test')['usage']['orders']);

        // Trial over -> read-only: reads work, writes refused.
        DB::table('tenants')->where('id', $tid)->update(['trial_ends_at' => now()->subDay()->toDateString()]); Subscription::forget();
        $this->props('/orders', 'client.test');
        $this->assertTrue($this->props('/dashboard', 'client.test')['subscription']['read_only']);
        $entry = ['money_account_id' => 1, 'direction' => 'out', 'amount_minor' => 1, 'category' => 'rent', 'entry_date' => now()->toDateString()];
        $this->post('http://client.test/accounts/entries', $entry)->assertStatus(402);
        $this->withHeader('X-Inertia', 'true')->post('http://client.test/accounts/entries', $entry)->assertSessionHas('error');
        $this->withoutHeader('X-Inertia');
        $this->get('http://client.test/export')->assertOk();

        // Super admin records 2 months -> active again.
        $this->actingAs($this->owner);
        $this->post($this->base."/platform/tenants/$tid/payments", ['amount_minor' => 300000, 'months' => 2, 'method' => 'bkash', 'reference' => 'TRX1', 'paid_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $t = DB::table('tenants')->where('id', $tid)->first();
        $this->assertSame(['active', now()->startOfDay()->addMonthsNoOverflow(2)->toDateString()], [$t->status, substr((string) $t->paid_until, 0, 10)]);
        Subscription::forget();
        $this->assertFalse(Subscription::state($tid)['read_only']);
        // Paid + 5 days past due = still OK (grace), +8 = read-only.
        DB::table('tenants')->where('id', $tid)->update(['paid_until' => now()->subDays(5)->toDateString()]); Subscription::forget();
        $this->assertFalse(Subscription::state($tid)['read_only']);
        DB::table('tenants')->where('id', $tid)->update(['paid_until' => now()->subDays(8)->toDateString()]); Subscription::forget();
        $this->assertTrue(Subscription::state($tid)['read_only']);
        // Suspend -> blocked even for reads; platform company cannot be suspended.
        $this->put($this->base."/platform/tenants/$tid", ['name' => 'Client Co', 'plan_id' => $starter, 'status' => 'suspended'])->assertSessionHasNoErrors();
        $this->put($this->base.'/platform/tenants/'.DB::table('tenants')->min('id'), ['name' => 'Sheikh IT', 'status' => 'suspended'])->assertSessionHasErrors('status');
        Subscription::forget();
        $this->actingAs($client)->get('http://client.test/dashboard')->assertStatus(402);
        // Domains + plans.
        $this->actingAs($this->owner)->post($this->base."/platform/tenants/$tid/domains", ['hostname' => 'https://ERP.Client.com/'])->assertSessionHasNoErrors();
        $this->assertTrue(DB::table('tenant_domains')->where('hostname', 'erp.client.com')->exists());
        $this->post($this->base.'/platform/plans', ['name' => 'Pro', 'price_minor' => 500000, 'users_limit' => 5, 'active' => true])->assertSessionHasNoErrors();
        $this->assertSame(4, DB::table('plans')->count());
        // Unlimited (no plan) company is never read-only.
        $this->assertFalse(Subscription::state((int) DB::table('tenants')->min('id'))['read_only']);
    }

    public function test_two_factor_login_audit_log_export_and_locale(): void {
        $this->post($this->base.'/profile/two-factor', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->post($this->base.'/profile/two-factor', ['password' => 'secret-pass'])->assertSessionHasNoErrors();
        $secret = $this->props('/profile/two-factor')['setup']['secret'];
        $this->post($this->base.'/profile/two-factor/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post($this->base.'/profile/two-factor/confirm', ['code' => Totp::code($secret)])->assertSessionHasNoErrors();
        $this->assertTrue($this->owner->fresh()->hasTwoFactor());
        $codes = $this->props('/profile/two-factor')['recovery'];
        $this->assertCount(8, $codes);

        $this->post($this->base.'/logout');
        $this->assertGuest();
        $this->post($this->base.'/login', ['email' => 'owner@example.com', 'password' => 'secret-pass'])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        $this->post($this->base.'/two-factor-challenge', ['code' => '123456'])->assertSessionHasErrors('code');
        $this->post($this->base.'/two-factor-challenge', ['code' => Totp::code($secret)])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->owner);
        // Recovery code works once.
        $this->post($this->base.'/logout');
        $this->post($this->base.'/login', ['email' => 'owner@example.com', 'password' => 'secret-pass']);
        $this->post($this->base.'/two-factor-challenge', ['recovery_code' => $codes[0]])->assertRedirect('/dashboard');
        $this->assertCount(7, (array) $this->owner->fresh()->two_factor_recovery_codes);

        // Audit trail: changes are recorded with result.
        $this->post($this->base.'/accounts/entries', ['money_account_id' => 999, 'direction' => 'out', 'amount_minor' => 1, 'category' => 'rent', 'entry_date' => now()->toDateString()]);
        $logs = collect($this->props('/audit')['logs']['data'])->map(fn ($l) => (array) $l);
        $this->assertTrue($logs->contains(fn ($l) => $l['action'] === 'auth.login'));
        $actions = $logs->pluck('status', 'action');
        $this->assertSame(422, $actions['accounts.store']);
        $this->assertArrayHasKey('two-factor.confirm', $actions->all());

        // Export: zip with one JSON per tenant table.
        $res = $this->get($this->base.'/export')->assertOk();
        $this->assertStringContainsString('opryx-export-', $res->headers->get('content-disposition'));
        $file = $res->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive(); $zip->open($file);
        $this->assertNotFalse($zip->locateName('sales_orders.json'));
        $this->assertFalse($zip->locateName('tenant_settings.json'));
        $zip->close();

        // Language + branding.
        $this->post($this->base.'/profile/locale', ['locale' => 'bn'])->assertSessionHasNoErrors();
        $this->assertSame('bn', $this->props('/dashboard')['auth']['user']['locale']);
        $this->post($this->base.'/settings', ['values' => ['company.address' => 'Mirpur, Dhaka', 'company.phone' => '01894024079']]);
        $png = \Illuminate\Http\UploadedFile::fake()->image('logo.png', 120, 60);
        $this->post($this->base.'/settings/logo', ['logo' => $png])->assertSessionHasNoErrors();
        $brand = $this->props('/dashboard')['brand'];
        $this->assertSame('Mirpur, Dhaka', $brand['address']);
        $this->assertStringStartsWith('data:image/png;base64,', $brand['logo']);
        $this->post($this->base.'/settings/logo', ['logo' => \Illuminate\Http\UploadedFile::fake()->create('x.svg', 5, 'image/svg+xml')])->assertSessionHasErrors('logo');
    }

    public function test_signup_only_on_platform_host_and_pwa_files(): void {
        auth()->logout();
        $this->get('http://app.test/signup')->assertNotFound();
        config(['opryx.signup_host' => 'app.test', 'opryx.tenant_root' => 'opryx.test']);
        $this->get('http://app.test/signup')->assertOk();
        $this->get('http://erp.test/signup')->assertNotFound();
        $data = ['company' => 'Fresh Brand', 'subdomain' => 'freshbrand', 'name' => 'F', 'email' => 'f@fresh.test', 'phone' => '01700000000', 'password' => 'fresh-pass-1', 'password_confirmation' => 'fresh-pass-1'];
        $this->post('http://app.test/signup', ['subdomain' => 'admin'] + $data)->assertSessionHasErrors('subdomain');
        $this->post('http://app.test/signup', $data)->assertOk();
        $t = DB::table('tenants')->where('name', 'Fresh Brand')->first();
        $this->assertSame('trial', $t->status);
        $this->assertTrue(DB::table('tenant_domains')->where('hostname', 'freshbrand.opryx.test')->exists());
        $this->post('http://app.test/signup', ['email' => 'g@fresh.test'] + $data)->assertSessionHasErrors('subdomain');
        $this->post('http://freshbrand.opryx.test/login', ['email' => 'f@fresh.test', 'password' => 'fresh-pass-1'])->assertRedirect();
        $this->get('http://freshbrand.opryx.test/dashboard')->assertOk();
        foreach (['manifest.webmanifest', 'sw.js', 'offline.html', 'icons/icon-512.png'] as $f) $this->assertFileExists(public_path($f));
        $this->assertSame('Opryx', json_decode(file_get_contents(public_path('manifest.webmanifest')), true)['short_name']);
    }
}
