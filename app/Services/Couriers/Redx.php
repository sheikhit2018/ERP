<?php
namespace App\Services\Couriers;

use App\Models\SalesOrder;
use App\Support\{Phone, Settings};
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\{Cache, Http};

/** RedX open API — header API-ACCESS-TOKEN: Bearer <token>. Delivery area is matched from the customer address. */
final class Redx implements CourierDriver {
    private function base(): string {
        return Settings::get('courier.redx.sandbox') === '1' ? 'https://sandbox.redx.com.bd/v1.0.0-beta' : 'https://openapi.redx.com.bd/v1.0.0-beta';
    }
    private function http() {
        $t = (string) Settings::get('courier.redx.token', '');
        if ($t === '') throw new CourierException('RedX API token is not set (Settings → Courier API).');
        return Http::timeout(20)->acceptJson()->withHeaders(['API-ACCESS-TOKEN' => 'Bearer '.$t]);
    }

    /** All RedX delivery areas (cached a day per company). */
    private function areas(): array {
        $key = 'redx_areas:'.app(TenantContext::class)->id().':'.md5($this->base());
        return Cache::remember($key, now()->addDay(), function () {
            $res = $this->http()->get($this->base().'/areas');
            if (!$res->successful()) throw new CourierException('RedX areas: HTTP '.$res->status());
            return array_map(fn ($a) => ['id' => (int) $a['id'], 'name' => (string) $a['name'], 'district' => (string) ($a['district_name'] ?? '')], $res->json('areas') ?? []);
        });
    }

    /** Longest RedX area name that appears in the address (e.g. "Mirpur 10"). */
    public static function matchArea(string $address, array $areas): ?array {
        $addr = ' '.mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $address)).' ';
        $best = null;
        foreach ($areas as $a) {
            $n = trim(mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $a['name'])));
            if ($n !== '' && str_contains($addr, " $n ") && (!$best || mb_strlen($n) > mb_strlen($best['_n']))) $best = $a + ['_n' => $n];
        }
        return $best;
    }

    public function book(SalesOrder $o, int $codMinor): array {
        $area = self::matchArea($o->customer['address'], $this->areas());
        if (!$area) throw new CourierException('RedX: could not find the delivery area in the address. Add the area name (e.g. "Mirpur 10") to the address, or book this parcel in the RedX panel.');
        $items = $o->items()->with('product:id,name,color,size')->get();
        $body = ['customer_name' => mb_substr($o->customer['name'], 0, 100), 'customer_phone' => Phone::normalize($o->customer['phone']),
            'delivery_area' => $area['name'], 'delivery_area_id' => $area['id'], 'customer_address' => mb_substr($o->customer['address'], 0, 250),
            'merchant_invoice_id' => (string) $o->id, 'cash_collection_amount' => (string) round($codMinor / 100, 2), 'parcel_weight' => 500,
            'instruction' => mb_substr((string) $o->note, 0, 200), 'value' => (string) round($o->totalMinor() / 100, 2), 'is_closed_box' => false,
            'parcel_details_json' => $items->map(fn ($i) => ['name' => $i->product->label, 'category' => 'clothing', 'value' => round($i->quantity * $i->unit_price_minor / 100, 2)])->values()->all()];
        if ($ps = (int) Settings::get('courier.redx.pickup_store_id', 0)) $body['pickup_store_id'] = $ps;
        $res = $this->http()->post($this->base().'/parcel', $body);
        $t = (string) ($res->json('tracking_id') ?? '');
        if (!$res->successful() || $t === '') throw new CourierException('RedX: '.($res->json('message') ?? 'HTTP '.$res->status()));
        return ['consignment_id' => $t, 'tracking_code' => null, 'raw' => (array) $res->json()];
    }

    public function status(string $id): array {
        $res = $this->http()->get($this->base().'/parcel/info/'.rawurlencode($id));
        $s = (string) ($res->json('parcel.status') ?? '');
        if (!$res->successful() || $s === '') throw new CourierException('RedX: '.($res->json('message') ?? 'HTTP '.$res->status()));
        return ['state' => self::state($s), 'status' => $s];
    }

    public function test(): string {
        $res = $this->http()->get($this->base().'/pickup/stores');
        if (!$res->successful()) throw new CourierException('RedX: '.($res->json('message') ?? 'HTTP '.$res->status()));
        $stores = collect($res->json('pickup_stores') ?? [])->map(fn ($s) => ($s['id'] ?? '?').' = '.($s['name'] ?? ''))->implode(', ');
        return 'Connected to RedX.'.($stores ? " Pickup stores: $stores" : '');
    }

    public static function state(string $s): string {
        $s = strtolower($s);
        return match (true) {
            str_contains($s, 'partial') => 'partial',
            $s === 'delivered' => 'delivered',
            str_contains($s, 'return'), str_contains($s, 'cancel') => 'returning',
            str_contains($s, 'hold') => 'hold',
            str_contains($s, 'pending'), str_contains($s, 'pickup'), str_contains($s, 'delivery'), str_contains($s, 'transit'), str_contains($s, 'area') => 'in_transit',
            default => 'unknown',
        };
    }
}
