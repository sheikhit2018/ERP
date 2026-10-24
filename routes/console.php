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
Schedule::command('opryx:sms-retry')->everyFiveMinutes()->withoutOverlapping();

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
Schedule::command('opryx:courier-sync')->hourly()->withoutOverlapping();
