<?php
namespace App\Services\Couriers;

use App\Models\SalesOrder;
use App\Support\{Phone, Settings};
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\{Cache, Http};

/** Pathao merchant API (Aladdin) — OAuth password grant, token cached per company. */
final class Pathao implements CourierDriver {
    private function base(): string {
        return Settings::get('courier.pathao.sandbox') === '1' ? 'https://courier-api-sandbox.pathao.com' : 'https://api-hermes.pathao.com';
    }

    private function token(): string {
        $cfg = ['client_id' => (string) Settings::get('courier.pathao.client_id', ''), 'client_secret' => (string) Settings::get('courier.pathao.client_secret', ''),
            'username' => (string) Settings::get('courier.pathao.username', ''), 'password' => (string) Settings::get('courier.pathao.password', ''), 'grant_type' => 'password'];
        foreach ($cfg as $k => $v) if ($v === '') throw new CourierException("Pathao $k is not set (Settings → Courier API).");
        $key = 'pathao_token:'.app(TenantContext::class)->id().':'.md5($this->base().$cfg['client_id'].$cfg['username']);
        $t = Cache::get($key);
        if ($t) return $t;
        $res = Http::timeout(20)->acceptJson()->post($this->base().'/aladdin/api/v1/issue-token', $cfg);
        $t = (string) ($res->json('access_token') ?? '');
        if (!$res->successful() || $t === '') throw new CourierException('Pathao login failed: '.($res->json('message') ?? 'HTTP '.$res->status()));
        Cache::put($key, $t, now()->addSeconds(max(300, (int) ($res->json('expires_in') ?? 3600) - 300)));
        return $t;
    }
    private function http() { return Http::timeout(20)->acceptJson()->withToken($this->token()); }

    public function book(SalesOrder $o, int $codMinor): array {
        $store = (int) Settings::get('courier.pathao.store_id', 0);
        if (!$store) throw new CourierException('Pathao store ID is not set (Settings → Courier API).');
        $items = $o->items()->with('product:id,name,color,size')->get();
        $res = $this->http()->post($this->base().'/aladdin/api/v1/orders', [
            'store_id' => $store, 'merchant_order_id' => (string) $o->id,
            'recipient_name' => mb_substr($o->customer['name'], 0, 100), 'recipient_phone' => Phone::normalize($o->customer['phone']),
            'recipient_address' => mb_substr($o->customer['address'], 0, 220),
            'delivery_type' => 48, 'item_type' => 2, 'item_quantity' => max(1, (int) $items->sum('quantity')), 'item_weight' => 0.5,
            'amount_to_collect' => (int) round($codMinor / 100), 'item_description' => mb_substr($items->map(fn ($i) => $i->quantity.'x '.$i->product->label)->implode(', '), 0, 250),
            'special_instruction' => mb_substr((string) $o->note, 0, 200),
        ]);
        $cid = (string) ($res->json('data.consignment_id') ?? '');
        if (!$res->successful() || $cid === '') throw new CourierException('Pathao: '.self::err($res));
        return ['consignment_id' => $cid, 'tracking_code' => null, 'raw' => (array) $res->json('data')];
    }

    public function status(string $cid): array {
        $res = $this->http()->get($this->base().'/aladdin/api/v1/orders/'.rawurlencode($cid).'/info');
        $s = (string) ($res->json('data.order_status') ?? $res->json('data.order_status_slug') ?? '');
        if (!$res->successful() || $s === '') throw new CourierException('Pathao: '.self::err($res));
        return ['state' => self::state($s), 'status' => $s];
    }

    public function test(): string {
        $res = $this->http()->get($this->base().'/aladdin/api/v1/stores');
        if (!$res->successful()) throw new CourierException('Pathao: '.self::err($res));
        $stores = collect($res->json('data.data') ?? [])->map(fn ($s) => ($s['store_id'] ?? '?').' = '.($s['store_name'] ?? ''))->implode(', ');
        return 'Connected to Pathao.'.($stores ? " Your stores: $stores" : '');
    }

    public static function state(string $s): string {
        $s = strtolower(str_replace(['_', '-'], ' ', $s));
        return match (true) {
            str_contains($s, 'partial') => 'partial',
            str_contains($s, 'delivered') && !str_contains($s, 'fail') => 'delivered',
            str_contains($s, 'return'), str_contains($s, 'cancel') => 'returning',
            str_contains($s, 'hold') => 'hold',
            str_contains($s, 'pending'), str_contains($s, 'pickup'), str_contains($s, 'transit'), str_contains($s, 'sorting'), str_contains($s, 'hub'),
                str_contains($s, 'assigned'), str_contains($s, 'picked'), str_contains($s, 'delivery') => 'in_transit',
            default => 'unknown',
        };
    }

    private static function err($res): string {
        $e = $res->json('errors');
        if (is_array($e) && $e) return collect($e)->flatten()->implode(' ');
        return (string) ($res->json('message') ?? 'HTTP '.$res->status());
    }
}
