<?php
namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Plan & billing state of a company. A company without a plan is internal / unlimited.
 * trial  → full access until trial_ends_at;  active → until paid_until + GRACE days;  after that: read-only.
 * suspended → no access (super admin only).
 */
final class Subscription {
    public const GRACE = 7;
    private static array $cache = [];

    public static function state(int $tenantId): array {
        if (isset(self::$cache[$tenantId])) return self::$cache[$tenantId];
        $t = DB::table('tenants')->where('id', $tenantId)->first(['id', 'plan_id', 'status', 'trial_ends_at', 'paid_until']);
        $plan = $t?->plan_id ? DB::table('plans')->where('id', $t->plan_id)->first() : null;
        $today = now()->startOfDay();
        $until = match (true) { !$t || !$plan => null, $t->status === 'trial' => $t->trial_ends_at, default => $t->paid_until };
        $days = $until ? (int) $today->diffInDays(\Illuminate\Support\Carbon::parse($until), false) : null;
        $readOnly = $plan && $t->status !== 'suspended' && ($until === null ? $t->status === 'trial' : $days < -($t->status === 'trial' ? 0 : self::GRACE));
        return self::$cache[$tenantId] = [
            'plan' => $plan ? ['id' => $plan->id, 'name' => $plan->name, 'price_minor' => (int) $plan->price_minor, 'users_limit' => $plan->users_limit, 'orders_limit' => $plan->orders_limit, 'locations_limit' => $plan->locations_limit] : null,
            'status' => $t->status ?? 'active', 'until' => $until, 'days_left' => $days, 'read_only' => (bool) $readOnly, 'suspended' => ($t->status ?? '') === 'suspended',
        ];
    }
    public static function forget(): void { self::$cache = []; }

    private static function fail(string $m): never { throw ValidationException::withMessages(['inventory' => [$m]]); }

    /** $kind: users | orders | locations. Throws when the plan limit is reached. */
    public static function assertWithin(int $tenantId, string $kind): void {
        $s = self::state($tenantId);
        $limit = $s['plan'][$kind.'_limit'] ?? null;
        if ($limit === null) return;
        $used = match ($kind) {
            'users' => DB::table('tenant_user')->where('tenant_id', $tenantId)->where('active', true)->count(),
            'orders' => DB::table('sales_orders')->where('tenant_id', $tenantId)->where('created_at', '>=', now()->startOfMonth())->count(),
            'locations' => DB::table('locations')->where('tenant_id', $tenantId)->where('is_main', false)->where('active', true)->count(),
        };
        if ($used >= $limit) self::fail("Your {$s['plan']['name']} plan allows $limit ".($kind === 'orders' ? 'orders per month' : ($kind === 'locations' ? 'shops / extra warehouses' : 'active users')).'. Upgrade the plan to add more.');
    }
}
