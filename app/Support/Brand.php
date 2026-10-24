<?php
namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Company branding used on invoices, receipts, manifests and the sidebar. Read straight from tenant_settings (no tenant context needed). */
final class Brand {
    public const KEYS = ['company.logo', 'company.address', 'company.phone', 'company.email', 'company.invoice_note', 'company.locale'];
    private static array $cache = [];
    public static function get(int $tenantId): array {
        if (isset(self::$cache[$tenantId])) return self::$cache[$tenantId];
        $rows = DB::table('tenant_settings')->where('tenant_id', $tenantId)->whereIn('key', self::KEYS)->get(['key', 'value']);
        $v = [];
        foreach ($rows as $r) { try { $v[$r->key] = $r->value === null ? null : decrypt($r->value, false); } catch (\Throwable) { $v[$r->key] = $r->value; } }
        return self::$cache[$tenantId] = ['logo' => $v['company.logo'] ?? null, 'address' => $v['company.address'] ?? null, 'phone' => $v['company.phone'] ?? null,
            'email' => $v['company.email'] ?? null, 'invoice_note' => $v['company.invoice_note'] ?? null, 'locale' => $v['company.locale'] ?? 'en'];
    }
    public static function forget(): void { self::$cache = []; }
}
