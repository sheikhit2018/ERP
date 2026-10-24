<?php
namespace App\Services;

use App\Models\SalesOrder;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;

/**
 * Customer delivery history by phone (this company's own orders):
 * success rate = delivered / (delivered + returned). Lost parcels are the courier's fault and are not counted.
 */
final class CustomerRisk {
    public const DELIVERED = ['delivered', 'partial_delivered'];
    public const RETURNED = ['return_pending', 'returned', 'returned_short', 'return_damaged'];
    public const OPEN = ['pending', 'confirmed', 'packed', 'shipped'];

    /** @return array<string, array> keyed by canonical phone */
    public static function forPhones(array $phones, ?int $exceptOrder = null): array {
        $phones = array_values(array_unique(array_filter(array_map([Phone::class, 'normalize'], $phones))));
        if (!$phones) return [];
        $in = fn (array $s) => "'".implode("','", $s)."'";
        $rows = SalesOrder::query()->whereIn('customer_phone', $phones)->when($exceptOrder, fn ($q) => $q->whereKeyNot($exceptOrder))
            ->groupBy('customer_phone')->select('customer_phone')->selectRaw('count(*) as orders,
                sum(case when status in ('.$in(self::DELIVERED).') then 1 else 0 end) as delivered,
                sum(case when status in ('.$in(self::RETURNED).') then 1 else 0 end) as returned,
                sum(case when status = \'cancelled\' then 1 else 0 end) as cancelled,
                sum(case when status in ('.$in(self::OPEN).') then 1 else 0 end) as open,
                max(created_at) as last_order_at')->get();
        $out = [];
        foreach ($phones as $p) $out[$p] = self::rate(['orders' => 0, 'delivered' => 0, 'returned' => 0, 'cancelled' => 0, 'open' => 0, 'last_order_at' => null]);
        foreach ($rows as $r) $out[$r->customer_phone] = self::rate([
            'orders' => (int) $r->orders, 'delivered' => (int) $r->delivered, 'returned' => (int) $r->returned,
            'cancelled' => (int) $r->cancelled, 'open' => (int) $r->open, 'last_order_at' => $r->last_order_at,
        ]);
        return $out;
    }

    public static function forPhone(string $phone, ?int $exceptOrder = null): array {
        $p = Phone::normalize($phone);
        return (self::forPhones([$p], $exceptOrder)[$p] ?? null) ?? self::rate(['orders' => 0, 'delivered' => 0, 'returned' => 0, 'cancelled' => 0, 'open' => 0, 'last_order_at' => null]);
    }

    /** Adds success_rate (0-100 or null) and level: new | good | watch | risky. */
    private static function rate(array $s): array {
        $closed = $s['delivered'] + $s['returned'];
        $s['success_rate'] = $closed ? (int) round(100 * $s['delivered'] / $closed) : null;
        $s['level'] = match (true) {
            $closed === 0 => 'new',
            $s['returned'] >= 2 && $s['success_rate'] < 60, $s['success_rate'] < 50 => 'risky',
            $s['success_rate'] < 80 => 'watch',
            default => 'good',
        };
        return $s;
    }
}
