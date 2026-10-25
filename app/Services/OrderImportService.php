<?php
namespace App\Services;

use App\Models\{IncomingOrder, OrderPayment, Product, SalesOrder};
use App\Support\{Phone, Settings};
use App\Tenancy\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Creates orders from outside: Excel/CSV files and online-store webhooks (WooCommerce, Shopify).
 * Every source is normalised to: {external_id, reference, customer{name,phone,address}, lines[{code,qty,price}],
 * delivery, discount, paid, paid_method, note, source}. Product code = our SKU or barcode.
 */
class OrderImportService {
    public function __construct(private InventoryService $inventory) {}

    /** Deterministic UUID so the same external order can never be created twice. */
    private function key(string $source, string $external): string {
        $h = md5(app(TenantContext::class)->id()."|$source|$external");
        return sprintf('%s-%s-4%s-a%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 17, 3), substr($h, 20, 12));
    }

    private function product(string $code): ?Product {
        $code = trim($code);
        if ($code === '') return null;
        return Product::where('barcode', $code)->first() ?? Product::whereRaw('lower(sku) = ?', [mb_strtolower($code)])->first();
    }

    /** @return SalesOrder  (throws ValidationException / HttpException with a readable message) */
    public function create(array $n, int $actor, string $status): SalesOrder {
        $lines = []; $missing = [];
        foreach ($n['lines'] as $l) {
            $p = $this->product((string) $l['code']);
            if (!$p) { $missing[] = $l['code'] ?: '(empty)'; continue; }
            $qty = max(1, (int) $l['qty']);
            $price = $l['price'] !== null && $l['price'] !== '' ? (int) $l['price'] : (int) $p->price_minor;
            if (isset($lines[$p->id])) { $lines[$p->id]['quantity'] += $qty; continue; }
            $lines[$p->id] = ['product_id' => $p->id, 'quantity' => $qty, 'unit_price_minor' => max(0, $price)];
        }
        if ($missing) throw ValidationException::withMessages(['inventory' => ['Unknown product code: '.implode(', ', array_unique($missing)).' (use the SKU or barcode from Opryx).']]);
        if (!$lines) throw ValidationException::withMessages(['inventory' => ['No products in this order.']]);
        $c = $n['customer'];
        if (trim((string) $c['name']) === '' || strlen(Phone::normalize($c['phone'])) < 10 || trim((string) $c['address']) === '')
            throw ValidationException::withMessages(['inventory' => ['Customer name, phone and address are required.']]);

        $o = $this->inventory->place(['idempotency_key' => $this->key($n['source'], (string) $n['external_id']), 'status' => $status, 'currency' => 'BDT',
            'customer' => ['name' => mb_substr(trim($c['name']), 0, 150), 'phone' => mb_substr(trim($c['phone']), 0, 40), 'address' => mb_substr(trim($c['address']), 0, 1000)],
            'items' => array_values($lines)], $actor);
        if ($o->wasRecentlyCreated) {
            $o->forceFill(['delivery_charge_minor' => max(0, (int) ($n['delivery'] ?? 0)), 'discount_minor' => max(0, (int) ($n['discount'] ?? 0)),
                'source' => $n['order_source'] ?? (in_array($n['source'], ['website', 'facebook', 'phone', 'whatsapp', 'pos', 'manual'], true) ? $n['source'] : 'website'),
                'note' => isset($n['note']) && $n['note'] !== '' ? mb_substr(($n['reference'] ? "[{$n['reference']}] " : '').$n['note'], 0, 1000) : ($n['reference'] ? "[{$n['reference']}]" : null)])->save();
            $paid = min((int) ($n['paid'] ?? 0), $o->totalMinor());
            if ($paid > 0) {
                $pay = OrderPayment::create(['sales_order_id' => $o->id, 'method' => $n['paid_method'] ?? 'other', 'amount_minor' => $paid,
                    'reference' => $n['reference'] ? mb_substr((string) $n['reference'], 0, 120) : null, 'received_at' => now(), 'created_by' => $actor]);
                Accounts::auto('order_payment', $pay->id, $pay->method, 'in', $paid, 'customer_advance', $actor, ['reference' => $pay->reference, 'note' => "Online payment, order #{$o->id}", 'party_type' => 'customer', 'party_id' => $o->id]);
            }
        }
        return $o;
    }

    /** Webhook / retry: store in the inbox, then try to create the order. Never throws. */
    public function receive(string $source, array $n, array $payload, int $actor, ?string $status = null): IncomingOrder {
        $in = IncomingOrder::firstOrNew(['source' => $source, 'external_id' => (string) $n['external_id']]);
        if ($in->exists && $in->status === 'imported') return $in;
        $in->fill(['reference' => $n['reference'] ? mb_substr((string) $n['reference'], 0, 80) : null, 'payload' => $payload]);
        try {
            $o = $this->create($n + ['source' => 'website'], $actor, $status ?? (Settings::get('orders.web_status', 'pending') === 'confirmed' ? 'confirmed' : 'pending'));
            $in->fill(['status' => 'imported', 'error' => null, 'sales_order_id' => $o->id]);
        } catch (ValidationException $e) {
            $in->fill(['status' => 'failed', 'error' => mb_substr(implode(' ', Arr::flatten($e->errors())), 0, 500)]);
        } catch (HttpExceptionInterface $e) {
            $in->fill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }
        $in->save();
        return $in;
    }

    /** The store cancelled an order that is already in Opryx: cancel it here too if it has not left the warehouse. */
    public function cancelFromStore(string $source, string $externalId, int $actor): bool {
        if ($externalId === '') return false;
        $in = IncomingOrder::where('source', $source)->where('external_id', $externalId)->first();
        if (!$in) return false;
        if ($in->status !== 'imported') { $in->fill(['status' => 'skipped', 'error' => 'Cancelled in the online store.'])->save(); return false; }
        $o = SalesOrder::find($in->sales_order_id);
        if (!$o || !in_array($o->status, ['pending', 'confirmed', 'packed'], true)) return false;
        $this->inventory->cancel($o->id, $actor);
        $o->forceFill(['note' => mb_substr(trim(($o->note ?? '').' [Cancelled in online store]'), 0, 1000)])->save();
        return true;
    }

    /** Retry a failed inbox entry (after adding the missing product / stock). */
    public function retry(IncomingOrder $in, int $actor): IncomingOrder {
        $n = match ($in->source) { 'woocommerce' => self::fromWoo($in->payload), 'shopify' => self::fromShopify($in->payload), default => null };
        abort_unless($n, 422, 'Cannot retry this entry.');
        $gated = $in->source === 'woocommerce' && \App\Http\Controllers\WebhookController::importStatuses();
        return $this->receive($in->source, $n, $in->payload, $actor, $gated ? 'confirmed' : null);
    }

    private static function minor(mixed $v): int { return (int) round(((float) str_replace(',', '', (string) $v)) * 100); }
    private static function join(array $parts): string { return implode(', ', array_values(array_filter(array_map(fn ($p) => trim((string) $p), $parts)))); }

    public static function fromWoo(array $p): ?array {
        if (empty($p['id']) || empty($p['line_items'])) return null;
        $s = $p['shipping'] ?? []; $b = $p['billing'] ?? [];
        $name = self::join([$s['first_name'] ?? '', $s['last_name'] ?? '']) ?: self::join([$b['first_name'] ?? '', $b['last_name'] ?? '']);
        $addr = self::join([$s['address_1'] ?? $b['address_1'] ?? '', $s['address_2'] ?? $b['address_2'] ?? '', $s['city'] ?? $b['city'] ?? '', $s['state'] ?? $b['state'] ?? '']);
        $paidOnline = ($p['payment_method'] ?? 'cod') !== 'cod' && (!empty($p['date_paid']) || in_array($p['status'] ?? '', ['processing', 'completed'], true));
        return ['external_id' => (string) $p['id'], 'reference' => 'WC#'.($p['number'] ?? $p['id']),
            'customer' => ['name' => str_replace(', ', ' ', $name), 'phone' => $s['phone'] ?? '' ?: ($b['phone'] ?? ''), 'address' => $addr],
            'lines' => array_map(fn ($l) => ['code' => (string) ($l['sku'] ?? ''), 'qty' => (int) ($l['quantity'] ?? 1),
                'price' => isset($l['price']) ? self::minor($l['price']) : null], $p['line_items']),
            'delivery' => self::minor($p['shipping_total'] ?? 0), 'discount' => self::minor($p['discount_total'] ?? 0),
            'paid' => $paidOnline ? self::minor($p['total'] ?? 0) : 0, 'paid_method' => str_contains(strtolower((string) ($p['payment_method'] ?? '')), 'bkash') ? 'bkash' : 'card',
            'note' => (string) ($p['customer_note'] ?? '')];
    }

    public static function fromShopify(array $p): ?array {
        if (empty($p['id']) || empty($p['line_items'])) return null;
        $s = $p['shipping_address'] ?? $p['billing_address'] ?? [];
        $ship = array_sum(array_map(fn ($l) => self::minor($l['price'] ?? 0), $p['shipping_lines'] ?? []));
        $paid = ($p['financial_status'] ?? '') === 'paid' ? self::minor($p['total_price'] ?? 0) : 0;
        return ['external_id' => (string) $p['id'], 'reference' => 'SH'.($p['name'] ?? '#'.$p['id']),
            'customer' => ['name' => $s['name'] ?? self::join([$s['first_name'] ?? '', $s['last_name'] ?? '']), 'phone' => ($s['phone'] ?? '') ?: ($p['phone'] ?? ($p['customer']['phone'] ?? '')),
                'address' => self::join([$s['address1'] ?? '', $s['address2'] ?? '', $s['city'] ?? '', $s['province'] ?? ''])],
            'lines' => array_map(fn ($l) => ['code' => (string) ($l['sku'] ?? ''), 'qty' => (int) ($l['quantity'] ?? 1), 'price' => isset($l['price']) ? self::minor($l['price']) : null], $p['line_items']),
            'delivery' => $ship, 'discount' => self::minor($p['total_discounts'] ?? 0), 'paid' => $paid,
            'paid_method' => str_contains(strtolower(implode(',', (array) ($p['payment_gateway_names'] ?? []))), 'bkash') ? 'bkash' : 'card', 'note' => (string) ($p['note'] ?? '')];
    }
}
