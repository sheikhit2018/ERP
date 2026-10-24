<?php
namespace App\Services;

use App\Models\{FinishedGoodStock, InventoryMovement, LedgerEntry, Product, PurchaseItem, PurchaseOrder, PurchaseReceipt, RawMaterial, RawMaterialStock, Supplier};
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\ValidationException;

/** Suppliers, purchase orders, goods received (stock in), supplier payments and balance due. */
final class PurchaseService {
    private function tx(callable $fn): mixed {
        return DB::transaction(function () use ($fn) {
            $t = DB::table('tenants')->where('id', app(TenantContext::class)->id())->lockForUpdate()->first();
            abort_unless($t && $t->active, 403, 'Inactive tenant.');
            return $fn();
        }, 5);
    }
    private function fail(string $m): never { throw ValidationException::withMessages(['inventory' => [$m]]); }

    public function saveSupplier(array $data): Supplier {
        $d = Validator::make($data, ['id' => 'nullable|integer', 'name' => 'required|string|max:150', 'phone' => 'nullable|string|max:40', 'address' => 'nullable|string|max:500',
            'note' => 'nullable|string|max:300', 'opening_due_minor' => 'nullable|integer|min:-100000000000|max:100000000000', 'active' => 'boolean'])->validate();
        $s = !empty($d['id']) ? Supplier::findOrFail($d['id']) : new Supplier();
        $s->fill(['name' => $d['name'], 'phone' => $d['phone'] ?? null, 'address' => $d['address'] ?? null, 'note' => $d['note'] ?? null,
            'opening_due_minor' => (int) ($d['opening_due_minor'] ?? 0), 'active' => $d['active'] ?? true])->save();
        return $s;
    }

    /** lines: [{product_id | raw_material_id, quantity, unit_cost_minor}] */
    public function createOrder(array $data, int $actor): PurchaseOrder {
        $d = Validator::make($data, ['supplier_id' => 'required|integer', 'order_date' => 'required|date', 'expected_date' => 'nullable|date', 'note' => 'nullable|string|max:300',
            'lines' => 'required|array|min:1|max:300', 'lines.*.product_id' => 'nullable|integer', 'lines.*.raw_material_id' => 'nullable|integer',
            'lines.*.quantity' => 'required|integer|min:1|max:1000000000', 'lines.*.unit_cost_minor' => 'required|integer|min:0|max:100000000000'])->validate();
        return $this->tx(function () use ($d, $actor) {
            if (!Supplier::whereKey($d['supplier_id'])->exists()) $this->fail('Choose a supplier.');
            foreach ($d['lines'] as $l) {
                if (empty($l['product_id']) === empty($l['raw_material_id'])) $this->fail('Each line needs one product or one raw material.');
                if (!empty($l['product_id']) && !Product::whereKey($l['product_id'])->exists()) $this->fail('Unknown product.');
                if (!empty($l['raw_material_id']) && !RawMaterial::whereKey($l['raw_material_id'])->exists()) $this->fail('Unknown raw material.');
            }
            $po = PurchaseOrder::create(['supplier_id' => $d['supplier_id'], 'status' => 'ordered', 'order_date' => $d['order_date'], 'expected_date' => $d['expected_date'] ?? null,
                'note' => $d['note'] ?? null, 'created_by' => $actor]);
            foreach ($d['lines'] as $l) PurchaseItem::create(['purchase_order_id' => $po->id, 'product_id' => $l['product_id'] ?? null, 'raw_material_id' => $l['raw_material_id'] ?? null,
                'quantity' => (int) $l['quantity'], 'unit_cost_minor' => (int) $l['unit_cost_minor']]);
            return $po;
        });
    }

    /**
     * Goods received: lines [{item_id, quantity}]. Products → available stock (cost becomes weighted average),
     * raw materials → material stock. Idempotent by key. More than ordered is refused.
     */
    public function receive(int $poId, array $data, int $actor): PurchaseReceipt {
        $d = Validator::make($data, ['idempotency_key' => 'required|uuid', 'received_on' => 'required|date', 'note' => 'nullable|string|max:300',
            'lines' => 'required|array|min:1', 'lines.*.item_id' => 'required|integer', 'lines.*.quantity' => 'required|integer|min:0|max:1000000000'])->validate();
        return $this->tx(function () use ($poId, $d, $actor) {
            if ($old = PurchaseReceipt::where('idempotency_key', $d['idempotency_key'])->first()) return $old;
            $po = PurchaseOrder::whereKey($poId)->lockForUpdate()->firstOrFail();
            if (!in_array($po->status, ['ordered', 'partial'], true)) $this->fail("Purchase is {$po->status}.");
            $items = PurchaseItem::where('purchase_order_id', $po->id)->lockForUpdate()->get()->keyBy('id');
            $value = 0; $any = false;
            foreach ($d['lines'] as $l) {
                $q = (int) $l['quantity']; if ($q === 0) continue;
                $it = $items[(int) $l['item_id']] ?? null;
                if (!$it) $this->fail('Line does not belong to this purchase.');
                if ($it->received_quantity + $q > $it->quantity) $this->fail('More than ordered for one of the lines.');
                $it->received_quantity += $q; $it->save(); $value += $q * (int) $it->unit_cost_minor; $any = true;
            }
            if (!$any) $this->fail('Enter the quantity received.');
            $rc = PurchaseReceipt::create(['purchase_order_id' => $po->id, 'received_on' => $d['received_on'], 'value_minor' => $value, 'note' => $d['note'] ?? null,
                'idempotency_key' => $d['idempotency_key'], 'created_by' => $actor]);
            foreach ($d['lines'] as $l) {
                $q = (int) $l['quantity']; if ($q === 0) continue;
                $it = $items[(int) $l['item_id']]; $ref = "PURCHASE:$po->id";
                if ($it->product_id) {
                    $st = FinishedGoodStock::where('product_id', $it->product_id)->lockForUpdate()->first();
                    $p = Product::whereKey($it->product_id)->lockForUpdate()->first();
                    $own = max(0, (int) $st->available_stock + (int) $st->reserved_stock + (int) $st->transit_stock);
                    $p->cost_minor = $own + $q ? intdiv((int) $p->cost_minor * $own + (int) $it->unit_cost_minor * $q, $own + $q) : (int) $it->unit_cost_minor;
                    $p->save();
                    $st->available_stock = (int) $st->available_stock + $q; $st->save();
                    InventoryMovement::create(['product_id' => $it->product_id, 'raw_material_id' => null, 'bucket' => 'available_stock', 'delta' => $q, 'reason' => 'stock_in_purchase', 'reference' => $ref, 'actor_id' => $actor]);
                } else {
                    $st = RawMaterialStock::where('raw_material_id', $it->raw_material_id)->lockForUpdate()->first();
                    $st->quantity = (int) $st->quantity + $q; $st->save();
                    InventoryMovement::create(['product_id' => null, 'raw_material_id' => $it->raw_material_id, 'bucket' => 'raw', 'delta' => $q, 'reason' => 'purchase', 'reference' => $ref, 'actor_id' => $actor]);
                }
            }
            $po->status = $items->every(fn ($i) => $i->received_quantity >= $i->quantity) ? 'received' : 'partial'; $po->save();
            return $rc;
        });
    }

    public function cancel(int $poId): void {
        $this->tx(function () use ($poId) {
            $po = PurchaseOrder::whereKey($poId)->lockForUpdate()->firstOrFail();
            if ($po->status !== 'ordered') $this->fail('Only a purchase with nothing received can be cancelled.');
            $po->status = 'cancelled'; $po->save();
        });
    }

    /** Pay a supplier from a money account (posted to the cash book). */
    public function pay(int $supplierId, array $data, int $actor): LedgerEntry {
        $d = Validator::make($data, ['money_account_id' => 'required|integer', 'amount_minor' => 'required|integer|min:1', 'entry_date' => 'required|date', 'reference' => 'nullable|string|max:120', 'note' => 'nullable|string|max:300'])->validate();
        $s = Supplier::findOrFail($supplierId);
        return Accounts::record(['money_account_id' => $d['money_account_id'], 'direction' => 'out', 'amount_minor' => $d['amount_minor'], 'category' => 'supplier_payment',
            'entry_date' => $d['entry_date'], 'reference' => $d['reference'] ?? null, 'note' => $d['note'] ?? "Payment to {$s->name}", 'party_type' => 'supplier', 'party_id' => $s->id], $actor);
    }

    /** Balance due per supplier = opening + goods received − payments (+ refunds). */
    public static function dues(?array $ids = null): array {
        $recv = DB::table('purchase_receipts as r')->join('purchase_orders as o', 'o.id', '=', 'r.purchase_order_id')->where('r.tenant_id', app(TenantContext::class)->id())
            ->when($ids, fn ($q) => $q->whereIn('o.supplier_id', $ids))->groupBy('o.supplier_id')->selectRaw('o.supplier_id, sum(r.value_minor) as v')->pluck('v', 'supplier_id');
        $paid = LedgerEntry::where('party_type', 'supplier')->when($ids, fn ($q) => $q->whereIn('party_id', $ids))->groupBy('party_id')
            ->selectRaw("party_id, sum(case when direction='out' then amount_minor else -amount_minor end) as p")->pluck('p', 'party_id');
        return Supplier::when($ids, fn ($q) => $q->whereIn('id', $ids))->get(['id', 'opening_due_minor'])
            ->mapWithKeys(fn ($s) => [$s->id => ['received' => (int) ($recv[$s->id] ?? 0), 'paid' => (int) ($paid[$s->id] ?? 0),
                'due' => (int) $s->opening_due_minor + (int) ($recv[$s->id] ?? 0) - (int) ($paid[$s->id] ?? 0)]])->all();
    }
}
