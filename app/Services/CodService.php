<?php
namespace App\Services;

use App\Models\{CourierPayment, CourierPaymentLine, OrderPayment, SalesOrder, Shipment};
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\ValidationException;

/** Advance payments and courier COD settlements (payment statements). */
class CodService {
    public const METHODS = ['bkash', 'nagad', 'rocket', 'bank', 'cash', 'card', 'other'];

    public function __construct(private InventoryService $inventory) {}

    private function transaction(callable $fn): mixed {
        return DB::transaction(function () use ($fn) {
            $tenant = DB::table('tenants')->where('id', app(TenantContext::class)->id())->lockForUpdate()->first();
            abort_unless($tenant && $tenant->active, 403, 'Inactive tenant.');
            return $fn();
        }, 5);
    }
    private function fail(string $m): never { throw ValidationException::withMessages(['inventory' => [$m]]); }

    /** Advance / prepaid money for an order (bKash, Nagad, bank…). Before handover it lowers the COD to collect. */
    public function addPayment(int $orderId, array $data, int $actor): OrderPayment {
        $d = Validator::make($data, ['method' => 'required|in:'.implode(',', self::METHODS), 'amount_minor' => 'required|integer|min:1|max:100000000000',
            'reference' => 'nullable|string|max:120'])->validate();
        return $this->transaction(function () use ($orderId, $d, $actor) {
            $o = SalesOrder::whereKey($orderId)->lockForUpdate()->firstOrFail();
            if ($o->status === 'cancelled') $this->fail('Order is cancelled.');
            if (!empty($d['reference']) && OrderPayment::where('method', $d['method'])->where('reference', $d['reference'])->exists())
                $this->fail("Transaction {$d['reference']} is already recorded.");
            $paid = (int) $o->payments()->sum('amount_minor');
            if ($paid + $d['amount_minor'] > $o->totalMinor()) $this->fail('Payment is more than the order total.');
            return OrderPayment::create(['sales_order_id' => $o->id, 'method' => $d['method'], 'amount_minor' => $d['amount_minor'],
                'reference' => $d['reference'] ?? null, 'received_at' => now(), 'created_by' => $actor]);
        });
    }

    /**
     * Import one courier payment statement.
     * lines: [{tracking?, invoice?, collected, charge}] — matched by courier tracking ID, or by invoice = our order number.
     * Parcels already settled or not found are skipped and reported; the rest are saved as one settlement.
     * With mark_delivered, matched parcels still "with courier" that collected money are marked delivered.
     * @return array{payment: ?CourierPayment, skipped: string[], delivered: int}
     */
    public function importStatement(array $data, int $actor): array {
        $d = Validator::make($data, [
            'idempotency_key' => 'required|uuid', 'courier' => 'required|string|max:40|regex:/^[a-z0-9_-]+$/',
            'reference' => 'nullable|string|max:120', 'paid_on' => 'required|date', 'note' => 'nullable|string|max:190', 'mark_delivered' => 'boolean',
            'lines' => 'required|array|min:1|max:5000', 'lines.*.tracking' => 'nullable|string|max:120', 'lines.*.invoice' => 'nullable|string|max:120',
            'lines.*.collected' => 'required|integer|min:0|max:100000000000', 'lines.*.charge' => 'nullable|integer|min:0|max:100000000000',
        ])->validate();
        $hash = hash('sha256', json_encode([$d['courier'], $d['reference'] ?? null, $d['paid_on'], $d['lines']]));
        return $this->transaction(function () use ($d, $hash, $actor) {
            $old = CourierPayment::where('idempotency_key', $d['idempotency_key'])->first();
            if ($old) { abort_unless(hash_equals($old->request_hash, $hash), 409, 'Idempotency key reused.'); return ['payment' => $old, 'skipped' => [], 'delivered' => 0]; }

            $tracks = array_values(array_filter(array_map(fn ($l) => trim((string) ($l['tracking'] ?? '')), $d['lines'])));
            $orderIds = array_values(array_filter(array_map(fn ($l) => (int) preg_replace('/\D/', '', (string) ($l['invoice'] ?? '')), $d['lines'])));
            $byTrack = Shipment::where('courier', $d['courier'])->whereIn('tracking_id', $tracks)->get()->keyBy('tracking_id');
            $byOrder = Shipment::where('courier', $d['courier'])->whereIn('sales_order_id', $orderIds)->get()->keyBy('sales_order_id');
            $skipped = []; $use = [];
            foreach ($d['lines'] as $i => $l) {
                $t = trim((string) ($l['tracking'] ?? '')); $inv = (int) preg_replace('/\D/', '', (string) ($l['invoice'] ?? ''));
                $s = ($t !== '' ? ($byTrack[$t] ?? null) : null) ?? ($inv ? ($byOrder[$inv] ?? null) : null);
                $label = $t !== '' ? $t : ('invoice '.($l['invoice'] ?? '?'));
                if (!$s) { $skipped[] = "$label: no {$d['courier']} parcel found"; continue; }
                if ($s->settled_at || isset($use[$s->id])) { $skipped[] = "$label (order #{$s->sales_order_id}): already settled"; continue; }
                $use[$s->id] = ['ship' => $s, 'collected' => (int) $l['collected'], 'charge' => (int) ($l['charge'] ?? 0)];
            }
            if (!$use) return ['payment' => null, 'skipped' => $skipped, 'delivered' => 0];

            $collected = array_sum(array_column($use, 'collected')); $charge = array_sum(array_column($use, 'charge'));
            $p = CourierPayment::create(['courier' => $d['courier'], 'reference' => $d['reference'] ?? null, 'paid_on' => $d['paid_on'],
                'parcel_count' => count($use), 'collected_minor' => $collected, 'charge_minor' => $charge, 'net_minor' => $collected - $charge,
                'note' => $d['note'] ?? null, 'idempotency_key' => $d['idempotency_key'], 'request_hash' => $hash, 'created_by' => $actor]);
            $delivered = 0;
            foreach ($use as $u) {
                $s = $u['ship'];
                CourierPaymentLine::create(['courier_payment_id' => $p->id, 'shipment_id' => $s->id, 'expected_minor' => (int) $s->cod_expected_minor,
                    'collected_minor' => $u['collected'], 'charge_minor' => $u['charge']]);
                $s->forceFill(['cod_collected_minor' => $u['collected'], 'courier_charge_minor' => $u['charge'], 'settled_at' => now()])->save();
                if (!empty($d['mark_delivered']) && $s->status === 'shipped' && $u['collected'] > 0) {
                    $this->inventory->delivered((int) $s->sales_order_id, $actor);
                    $delivered++;
                }
            }
            return ['payment' => $p, 'skipped' => $skipped, 'delivered' => $delivered];
        });
    }
}
