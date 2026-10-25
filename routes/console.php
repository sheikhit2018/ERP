<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\{DB, Schedule};

// Resend customer SMS that are still queued or failed (gateway down, timeout). Max 3 tries within 2 days.
Artisan::command('opryx:sms-retry', function () {
    $rows = DB::table('sms_logs')->whereIn('status', ['queued', 'failed'])->where('attempts', '<', 3)
        ->where('created_at', '<', now()->subMinute())->where('created_at', '>', now()->subDays(2))->orderBy('id')->limit(500)->get(['id', 'tenant_id']);
    foreach ($rows as $r) \App\Services\Sms::deliver((int) $r->tenant_id, (int) $r->id);
    $this->info(count($rows).' SMS processed.');
})->purpose('Send queued / failed customer SMS');

// Hostinger cron (hPanel → Advanced → Cron Jobs, every minute):  php /home/USER/domains/.../artisan schedule:run
// Shared hosting has no proc_open, so scheduled jobs run in-process (Schedule::call), not as sub-processes.
Schedule::call(fn () => Artisan::call('opryx:sms-retry'))->name('opryx-sms-retry')->everyFiveMinutes()->withoutOverlapping();

// Ask courier APIs for the status of open parcels (companies with "check automatically" switched on).
Artisan::command('opryx:courier-sync', function () {
    $ctx = app(\App\Tenancy\TenantContext::class);
    foreach (DB::table('tenants')->where('active', true)->pluck('id') as $tid) {
        $ctx->set((int) $tid); \App\Support\Settings::flush();
        try {
            if (\App\Support\Settings::get('courier.auto_sync') !== '1' || !\App\Services\Couriers\CourierApi::enabled()) continue;
            $r = app(\App\Services\Couriers\CourierApi::class)->sync(\App\Support\SystemActor::id(), 300);
            $this->info("Company $tid: checked {$r['checked']}, delivered {$r['delivered']}, errors {$r['errors']}");
        } catch (\Throwable $e) { report($e); } finally { $ctx->clear(); }
    }
})->purpose('Sync parcel statuses from courier APIs');
Schedule::call(fn () => Artisan::call('opryx:courier-sync'))->name('opryx-courier-sync')->hourly()->withoutOverlapping();

// Make (or remove) a platform super admin: php artisan opryx:super-admin you@example.com [--remove]
Artisan::command('opryx:super-admin {email} {--remove}', function (string $email) {
    $n = DB::table('users')->where('email', strtolower($email))->update(['is_super_admin' => !$this->option('remove')]);
    $n ? $this->info(($this->option('remove') ? 'Removed' : 'Granted').' super admin for '.$email) : $this->error('No user with that email.');
})->purpose('Grant or remove platform super admin');

// Keep the activity log a sensible size (default: one year).
Artisan::command('opryx:prune-audit {--days=365}', function () {
    $n = DB::table('audit_logs')->where('created_at', '<', now()->subDays((int) $this->option('days')))->delete();
    $this->info("$n old activity rows removed.");
})->purpose('Delete activity-log rows older than N days');
Schedule::call(fn () => Artisan::call('opryx:prune-audit'))->name('opryx-prune-audit')->weekly();

// Support check without a shell: php artisan opryx:diag
Artisan::command('opryx:diag {--payload : print order meta of the latest website order}', function () {
    foreach (DB::table('tenants')->orderBy('id')->get(['id', 'name']) as $t) {
        app(\App\Tenancy\TenantContext::class)->set((int) $t->id); \App\Support\Settings::flush();
        $this->line("#{$t->id} {$t->name} | woo gate: ".(\App\Support\Settings::get('store.woo_import_status') ?: '(every order)').' | web status: '.\App\Support\Settings::get('orders.web_status', 'pending')
            .' | inbox: '.DB::table('incoming_orders')->where('tenant_id', $t->id)->count().' | orders: '.DB::table('sales_orders')->where('tenant_id', $t->id)->count());
        foreach (DB::table('audit_logs')->where('tenant_id', $t->id)->where('action', 'like', 'webhook.%')->orderByDesc('id')->limit(8)->get() as $h) $this->line("   {$h->created_at} {$h->status} {$h->meta}");
        if ($this->option('payload') && ($in = \App\Models\IncomingOrder::latest('id')->first())) {
            $p = $in->payload;
            $this->line('   meta: '.json_encode(array_map(fn ($m) => [$m['key'] ?? '', $m['value'] ?? ''], $p['meta_data'] ?? []), JSON_UNESCAPED_UNICODE));
            foreach ($p['line_items'] ?? [] as $l) $this->line('   line: '.json_encode(['name' => $l['name'] ?? '', 'pid' => $l['product_id'] ?? '', 'vid' => $l['variation_id'] ?? '', 'sku' => $l['sku'] ?? '', 'meta' => $l['meta_data'] ?? []], JSON_UNESCAPED_UNICODE));
        }
    }
})->purpose('Show integration status per company');
