<?php
namespace App\Services;

use App\Models\{SalesOrder, SmsLog};
use App\Support\{Phone, Settings};
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\{DB, Http};

/**
 * Customer SMS for order events (confirmed, shipped, delivered).
 * queue() records the SMS after the database commit; flush() sends it after the response has gone to the
 * browser, so screens never wait for the SMS gateway. opryx:sms-retry resends anything left queued / failed.
 */
final class Sms {
    public const EVENTS = ['confirmed', 'shipped', 'delivered'];
    private static array $pending = [];
    private static bool $hooked = false;

    /** Call inside or outside a transaction; nothing is queued if the transaction rolls back. */
    public static function orderEvent(int $orderId, string $event): void {
        $tenant = app(TenantContext::class)->id();
        DB::afterCommit(function () use ($orderId, $event, $tenant) {
            try { self::queue($tenant, $orderId, $event); } catch (\Throwable $e) { report($e); }
        });
    }

    private static function queue(int $tenant, int $orderId, string $event): void {
        $ctx = app(TenantContext::class); $ctx->set($tenant);
        if (Settings::get('sms.enabled') !== '1' || Settings::get("sms.on_$event") !== '1') return;
        $tpl = trim((string) Settings::get("sms.tpl_$event", ''));
        if ($tpl === '' || !Settings::get('sms.api_key') && Settings::get('sms.provider') !== 'custom') return;
        $o = SalesOrder::with('shipment')->find($orderId);
        if (!$o || SmsLog::where('sales_order_id', $o->id)->where('event', $event)->exists()) return;
        // The text is written when it is sent, so it shows the final order (delivery charge, tracking…).
        $log = SmsLog::create(['sales_order_id' => $o->id, 'event' => $event, 'phone' => Phone::normalize($o->customer['phone'] ?? ''), 'message' => '', 'status' => 'queued']);
        self::$pending[] = [$tenant, $log->id];
        if (!self::$hooked) { self::$hooked = true; app()->terminating(fn () => self::flush()); }
    }

    public static function render(string $tpl, SalesOrder $o): string {
        $tk = fn ($m) => number_format(((int) $m) / 100, 0);
        $vars = ['{name}' => $o->customer['name'] ?? '', '{order}' => (string) $o->id, '{total}' => $tk($o->totalMinor()),
            '{cod}' => $tk($o->shipment?->cod_expected_minor ?? $o->codDueMinor()), '{courier}' => ucfirst((string) ($o->shipment?->courier ?? '')),
            '{tracking}' => (string) ($o->shipment?->tracking_id ?? ''), '{company}' => (string) DB::table('tenants')->where('id', $o->tenant_id)->value('name')];
        return mb_substr(strtr($tpl, $vars), 0, 600);
    }

    /** Send everything queued in this request (runs after the response). */
    public static function flush(): void {
        $items = self::$pending; self::$pending = []; self::$hooked = false;
        foreach ($items as [$tenant, $id]) self::deliver($tenant, $id);
    }

    public static function deliver(int $tenant, int $id): void {
        $ctx = app(TenantContext::class); $ctx->set($tenant);
        try {
            $log = SmsLog::find($id);
            if (!$log || $log->status === 'sent') return;
            if ($log->message === '') {
                $o = SalesOrder::with('shipment')->find($log->sales_order_id);
                $tpl = trim((string) Settings::get("sms.tpl_{$log->event}", ''));
                if (!$o || $tpl === '') { $log->forceFill(['status' => 'failed', 'error' => 'No message template', 'attempts' => 3])->save(); return; }
                $log->message = self::render($tpl, $o); $log->save();
            }
            [$ok, $err] = self::send($log->phone, $log->message);
            $log->forceFill(['status' => $ok ? 'sent' : 'failed', 'error' => $ok ? null : mb_substr((string) $err, 0, 300), 'attempts' => $log->attempts + 1])->save();
        } catch (\Throwable $e) { report($e); }
        finally { $ctx->clear(); }
    }

    /** @return array{0: bool, 1: ?string} — uses the current company's gateway settings. */
    public static function send(string $phone, string $message): array {
        $p = Phone::normalize($phone);
        if (!preg_match('/^01\d{9}$/', $p)) return [false, "Not a Bangladeshi mobile number: $phone"];
        $intl = '88'.$p; $key = (string) Settings::get('sms.api_key', ''); $sid = (string) Settings::get('sms.sender_id', '');
        try {
            $http = Http::timeout(10)->acceptJson();
            switch (Settings::get('sms.provider', 'bulksmsbd')) {
                case 'bulksmsbd':
                    $b = $http->asForm()->post('https://bulksmsbd.net/api/smsapi', ['api_key' => $key, 'type' => 'text', 'senderid' => $sid, 'number' => $intl, 'message' => $message])->json();
                    return (int) data_get($b, 'response_code') === 202 ? [true, null] : [false, data_get($b, 'error_message') ?: 'BulkSMSBD code '.data_get($b, 'response_code')];
                case 'smsnetbd':
                    $b = $http->asForm()->post('https://api.sms.net.bd/sendsms', array_filter(['api_key' => $key, 'msg' => $message, 'to' => $intl, 'sender_id' => $sid ?: null]))->json();
                    return is_numeric(data_get($b, 'error')) && (int) data_get($b, 'error') === 0 ? [true, null] : [false, data_get($b, 'msg') ?: 'SMS.net.bd error '.data_get($b, 'error')];
                case 'greenweb':
                    $b = $http->asForm()->post('https://api.greenweb.com.bd/api.php', ['token' => $key, 'to' => $intl, 'message' => $message, 'json' => 1])->json();
                    return data_get($b, '0.status') === 'SENT' ? [true, null] : [false, data_get($b, '0.statusmsg') ?: 'Greenweb error'];
                case 'sslwireless':
                    $b = $http->asJson()->post('https://smsplus.sslwireless.com/api/v3/send-sms', ['api_token' => $key, 'sid' => $sid, 'msisdn' => $intl, 'sms' => $message, 'csms_id' => uniqid()])->json();
                    return stripos((string) data_get($b, 'status'), 'SUCCESS') !== false ? [true, null] : [false, data_get($b, 'error_message') ?: 'SSL Wireless error'];
                case 'custom':
                    $url = (string) Settings::get('sms.url', '');
                    if (!str_starts_with($url, 'https://') && !str_starts_with($url, 'http://')) return [false, 'Set the custom SMS URL.'];
                    $res = Http::timeout(10)->get(strtr($url, ['{phone}' => urlencode($intl), '{message}' => urlencode($message)]));
                    return $res->successful() ? [true, null] : [false, 'HTTP '.$res->status()];
            }
            return [false, 'Unknown SMS provider'];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }
}
