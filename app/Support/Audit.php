<?php
namespace App\Support;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Append-only activity trail. Never stores passwords, tokens or request bodies. */
final class Audit {
    public static function log(string $action, array $meta = [], ?int $userId = null, ?int $tenantId = null): void {
        try {
            $r = request();
            if ($tenantId === null) { try { $tenantId = app(TenantContext::class)->id(); } catch (\LogicException) { $tenantId = $r?->attributes->get('tenant')->id ?? null; } }
            // Screens outside the company middleware (login, 2FA): find the company from the domain.
            if ($tenantId === null && $r) $tenantId = DB::table('tenant_domains')->where('hostname', strtolower($r->getHost()))->value('tenant_id');
            DB::table('audit_logs')->insert([
                'tenant_id' => $tenantId, 'user_id' => $userId ?? auth()->id(),
                'action' => mb_substr($action, 0, 80), 'method' => $r?->method(), 'path' => $r ? mb_substr('/'.ltrim($r->path(), '/'), 0, 200) : null,
                'status' => $meta['_status'] ?? null, 'ip' => $r?->ip(), 'agent' => $r ? mb_substr((string) $r->userAgent(), 0, 160) : null,
                'meta' => ($m = array_diff_key($meta, ['_status' => 1])) ? json_encode($m, JSON_UNESCAPED_UNICODE) : null, 'created_at' => now(),
            ]);
        } catch (\Throwable) { /* auditing must never break the request */ }
    }
}
