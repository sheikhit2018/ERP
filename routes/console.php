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
