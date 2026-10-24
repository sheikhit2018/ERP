<?php
namespace App\Services\Couriers;

use App\Models\SalesOrder;
use App\Support\{Phone, Settings};
use Illuminate\Support\Facades\Http;

/** Steadfast (Packzy) merchant API — https://portal.packzy.com/api/v1, headers Api-Key / Secret-Key. */
final class Steadfast implements CourierDriver {
    public const BASE = 'https://portal.packzy.com/api/v1';

    private function http() {
        $k = (string) Settings::get('courier.steadfast.api_key', ''); $s = (string) Settings::get('courier.steadfast.secret_key', '');
        if ($k === '' || $s === '') throw new CourierException('Steadfast API key and secret key are not set (Settings → Courier API).');
        return Http::timeout(20)->acceptJson()->withHeaders(['Api-Key' => $k, 'Secret-Key' => $s]);
    }

    public function book(SalesOrder $o, int $codMinor): array {
        $items = $o->items()->with('product:id,name,color,size')->get()->map(fn ($i) => $i->quantity.'x '.$i->product->label)->implode(', ');
        $res = $this->http()->post(self::BASE.'/create_order', [
            'invoice' => (string) $o->id, 'recipient_name' => mb_substr($o->customer['name'], 0, 100), 'recipient_phone' => Phone::normalize($o->customer['phone']),
            'recipient_address' => mb_substr($o->customer['address'], 0, 250), 'cod_amount' => round($codMinor / 100, 2),
            'note' => mb_substr((string) $o->note, 0, 200), 'item_description' => mb_substr($items, 0, 250),
        ]);
        $b = $res->json() ?? [];
        $c = $b['consignment'] ?? null;
        if (!$res->successful() || (int) ($b['status'] ?? 0) !== 200 || empty($c['consignment_id']))
            throw new CourierException('Steadfast: '.self::err($b, $res->status()));
        return ['consignment_id' => (string) $c['consignment_id'], 'tracking_code' => isset($c['tracking_code']) ? (string) $c['tracking_code'] : null, 'raw' => $c];
    }

    public function status(string $cid): array {
        $res = $this->http()->get(self::BASE.'/status_by_cid/'.rawurlencode($cid));
        $s = (string) ($res->json('delivery_status') ?? '');
        if (!$res->successful() || $s === '') throw new CourierException('Steadfast: '.self::err($res->json() ?? [], $res->status()));
        return ['state' => self::state($s), 'status' => $s];
    }

    public function test(): string {
        $res = $this->http()->get(self::BASE.'/get_balance');
        if (!$res->successful() || $res->json('current_balance') === null) throw new CourierException('Steadfast: '.self::err($res->json() ?? [], $res->status()));
        return 'Connected to Steadfast. Current balance: ৳'.number_format((float) $res->json('current_balance'), 2);
    }

    /** Steadfast delivery_status → normalised state. */
    public static function state(string $s): string {
        return match (strtolower($s)) {
            'delivered', 'delivered_approval_pending' => 'delivered',
            'partial_delivered', 'partial_delivered_approval_pending' => 'partial',
            'cancelled', 'cancelled_approval_pending' => 'returning',
            'hold' => 'hold', 'in_review', 'pending' => 'in_transit',
            default => 'unknown',
        };
    }

    private static function err(array $b, int $code): string {
        if (!empty($b['errors']) && is_array($b['errors'])) return implode(' ', array_map(fn ($e) => is_array($e) ? implode(' ', $e) : (string) $e, $b['errors']));
        return (string) ($b['message'] ?? "HTTP $code");
    }
}
