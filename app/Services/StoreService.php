<?php
namespace App\Services;

use App\Models\{FinishedGoodStock, Location, LocationStock, PosSale, PosSaleItem, Product, StockCount, StockCountLine, StockTransfer, StockTransferItem};
use App\Support\Phone;
use Illuminate\Support\Facades\{DB, Validator};

/**
 * Locations, transfers, POS and stock counts — built on the same tenant lock and stock buckets as InventoryService.
 * Main warehouse quantity = available_stock − Σ location_stocks (stock held at shops / outlets).
 */
final class StoreService extends InventoryService {
    public static function main(): Location {
        return Location::where('is_main', true)->first() ?? Location::create(['name' => 'Main warehouse', 'type' => 'warehouse', 'is_main' => true]);
    }

    /** [product_id => qty] at one location (main is computed). */
    public static function quantities(Location $loc, ?array $productIds = null): array {
        if (!$loc->is_main) return LocationStock::where('location_id', $loc->id)->when($productIds, fn ($q) => $q->whereIn('product_id', $productIds))->pluck('quantity', 'product_id')->map(fn ($v) => (int) $v)->all();
        $held = LocationStock::when($productIds, fn ($q) => $q->whereIn('product_id', $productIds))->groupBy('product_id')->selectRaw('product_id, sum(quantity) as q')->pluck('q', 'product_id');
        return FinishedGoodStock::when($productIds, fn ($q) => $q->whereIn('product_id', $productIds))->pluck('available_stock', 'product_id')
            ->map(fn ($a, $pid) => max(0, (int) $a - (int) ($held[$pid] ?? 0)))->all();
    }

    public function saveLocation(array $data): Location {
        $d = Validator::make($data, ['id' => 'nullable|integer', 'name' => 'required|string|max:80', 'type' => 'required|in:warehouse,shop', 'address' => 'nullable|string|max:200', 'active' => 'boolean'])->validate();
        self::main();
        $l = !empty($d['id']) ? Location::findOrFail($d['id']) : new Location();
        if (!$l->exists || (!$l->active && ($d['active'] ?? true))) \App\Support\Subscription::assertWithin(app(\App\Tenancy\TenantContext::class)->id(), 'locations');
        if (Location::where('name', $d['name'])->where('id', '!=', $l->id ?? 0)->exists()) $this->fail('A location with that name already exists.');
        if ($l->is_main && isset($d['active']) && !$d['active']) $this->fail('The main warehouse cannot be deactivated.');
        if ($l->exists && !$l->is_main && isset($d['active']) && !$d['active'] && LocationStock::where('location_id', $l->id)->where('quantity', '>', 0)->exists()) $this->fail('Move this location\'s stock out before deactivating it.');
        $l->fill(['name' => $d['name'], 'type' => $l->is_main ? 'warehouse' : $d['type'], 'address' => $d['address'] ?? null, 'active' => $d['active'] ?? true])->save();
        return $l;
    }

    private function bump(Location $loc, int $productId, int $delta): void {
        if ($loc->is_main || $delta === 0) return;
        $row = LocationStock::where('location_id', $loc->id)->where('product_id', $productId)->lockForUpdate()->first()
            ?? LocationStock::create(['location_id' => $loc->id, 'product_id' => $productId, 'quantity' => 0]);
        if ((int) $row->quantity + $delta < 0) {
            $sku = Product::whereKey($productId)->value('sku');
            $this->fail("Only {$row->quantity} pcs of $sku at {$loc->name}.");
        }
        $row->quantity = (int) $row->quantity + $delta; $row->save();
    }

    private function lines(array $items, string $key = 'quantity'): array {
        $out = [];
        foreach ($items as $i) { $pid = (int) ($i['product_id'] ?? 0); $q = (int) ($i[$key] ?? 0); if ($pid && $q > 0) $out[$pid] = ($out[$pid] ?? 0) + $q; }
        ksort($out);
        return $out;
    }

    /** Move pieces between locations. Total stock does not change; only where it sits. */
    public function transfer(array $data, int $actor): StockTransfer {
        $d = Validator::make($data, ['idempotency_key' => 'required|uuid', 'from_location_id' => 'required|integer|different:to_location_id', 'to_location_id' => 'required|integer',
            'note' => 'nullable|string|max:200', 'items' => 'required|array|min:1|max:500', 'items.*.product_id' => 'required|integer', 'items.*.quantity' => 'required|integer|min:1|max:1000000'])->validate();
        return $this->transaction(function () use ($d, $actor) {
            if ($old = StockTransfer::where('idempotency_key', $d['idempotency_key'])->first()) return $old;
            $from = Location::where('active', true)->findOrFail($d['from_location_id']); $to = Location::where('active', true)->findOrFail($d['to_location_id']);
            $lines = $this->lines($d['items']);
            if (Product::whereIn('id', array_keys($lines))->count() !== count($lines)) $this->fail('Unknown product in the list.');
            if ($from->is_main) {
                $have = self::quantities($from, array_keys($lines));
                foreach ($lines as $pid => $q) if (($have[$pid] ?? 0) < $q) $this->fail('Only '.($have[$pid] ?? 0).' pcs of '.Product::whereKey($pid)->value('sku').' free in the main warehouse.');
            }
            $t = StockTransfer::create(['idempotency_key' => $d['idempotency_key'], 'from_location_id' => $from->id, 'to_location_id' => $to->id, 'piece_count' => array_sum($lines), 'note' => $d['note'] ?? null, 'created_by' => $actor]);
            foreach ($lines as $pid => $q) {
                $this->bump($from, $pid, -$q); $this->bump($to, $pid, $q);
                StockTransferItem::create(['stock_transfer_id' => $t->id, 'product_id' => $pid, 'quantity' => $q]);
            }
            return $t;
        });
    }

    /** Counter sale: stock leaves immediately, money lands in the chosen account. */
    public function sell(array $data, int $actor): PosSale {
        $d = Validator::make($data, ['idempotency_key' => 'required|uuid', 'location_id' => 'required|integer', 'method' => 'required|in:cash,bkash,nagad,card,bank',
            'discount_minor' => 'nullable|integer|min:0', 'tendered_minor' => 'nullable|integer|min:0', 'customer_name' => 'nullable|string|max:120', 'customer_phone' => 'nullable|string|max:40',
            'items' => 'required|array|min:1|max:200', 'items.*.product_id' => 'required|integer', 'items.*.quantity' => 'required|integer|min:1|max:100000',
            'items.*.unit_price_minor' => 'required|integer|min:0|max:1000000000'])->validate();
        $hash = $this->hash([$d['location_id'], $d['method'], $d['discount_minor'] ?? 0, array_map(fn ($i) => [(int) $i['product_id'], (int) $i['quantity'], (int) $i['unit_price_minor']], $d['items'])]);
        return $this->transaction(function () use ($d, $actor, $hash) {
            if ($old = PosSale::where('idempotency_key', $d['idempotency_key'])->first()) return $this->replay($old, $hash);
            $loc = Location::where('active', true)->findOrFail($d['location_id']);
            $items = []; foreach ($d['items'] as $i) { $k = (int) $i['product_id'].':'.(int) $i['unit_price_minor']; $items[$k] = ['product_id' => (int) $i['product_id'], 'unit_price_minor' => (int) $i['unit_price_minor'], 'quantity' => ($items[$k]['quantity'] ?? 0) + (int) $i['quantity']]; }
            $products = Product::whereIn('id', array_column($items, 'product_id'))->get()->keyBy('id');
            $sub = 0; $cost = 0;
            foreach ($items as $i) { if (!$products->has($i['product_id'])) $this->fail('Unknown product.'); $sub += $i['quantity'] * $i['unit_price_minor']; $cost += $i['quantity'] * (int) $products[$i['product_id']]->cost_minor; }
            $disc = min((int) ($d['discount_minor'] ?? 0), $sub); $total = $sub - $disc;
            if ($d['method'] === 'cash' && isset($d['tendered_minor']) && $d['tendered_minor'] > 0 && $d['tendered_minor'] < $total) $this->fail('Cash received is less than the total.');
            $sale = PosSale::create(['idempotency_key' => $d['idempotency_key'], 'request_hash' => $hash, 'location_id' => $loc->id, 'customer_name' => $d['customer_name'] ?? null,
                'customer_phone' => !empty($d['customer_phone']) ? Phone::normalize($d['customer_phone']) : null, 'subtotal_minor' => $sub, 'discount_minor' => $disc, 'total_minor' => $total,
                'cost_minor' => $cost, 'method' => $d['method'], 'tendered_minor' => (int) ($d['tendered_minor'] ?? 0), 'created_by' => $actor]);
            foreach ($items as $i) {
                PosSaleItem::create($i + ['pos_sale_id' => $sale->id, 'unit_cost_minor' => (int) $products[$i['product_id']]->cost_minor]);
                $this->move($this->stock($i['product_id']), 'available_stock', -$i['quantity'], 'pos_sale', "POS:$sale->id", $actor);
                if ($loc->is_main) $this->assertMainCovers($i['product_id']); else $this->bump($loc, $i['product_id'], -$i['quantity']);
            }
            $entry = Accounts::auto('pos_sale', $sale->id, $d['method'], 'in', $total, 'pos_sale', $actor, ['note' => "POS sale #$sale->id · {$loc->name}", 'reference' => "POS-$sale->id"]);
            if ($entry) { $sale->money_account_id = $entry->money_account_id; $sale->save(); }
            return $sale;
        });
    }

    /** Void a counter sale (same-day mistake / full refund): stock goes back where it came from and the cash entry is reversed. */
    public function void(int $id, int $actor): PosSale {
        return $this->transaction(function () use ($id, $actor) {
            $sale = PosSale::with('items')->lockForUpdate()->findOrFail($id);
            if ($sale->status === 'void') return $sale;
            $loc = Location::findOrFail($sale->location_id);
            foreach ($sale->items as $i) { $this->move($this->stock((int) $i->product_id), 'available_stock', (int) $i->quantity, 'pos_void', "POS:$sale->id", $actor); $this->bump($loc, (int) $i->product_id, (int) $i->quantity); }
            if ($sale->money_account_id && $sale->total_minor > 0)
                Accounts::auto('pos_void', $sale->id, $sale->method, 'out', (int) $sale->total_minor, 'pos_sale', $actor, ['note' => "Refund / void POS #$sale->id", 'reference' => "POS-$sale->id"]);
            $sale->forceFill(['status' => 'void', 'voided_at' => now()])->save();
            return $sale;
        });
    }

    /** Start a physical count: snapshot of system quantity is taken when the count is approved, so sales during counting are respected. */
    public function startCount(int $locationId, ?string $note, int $actor): StockCount {
        $loc = Location::where('active', true)->findOrFail($locationId);
        if (StockCount::where('location_id', $loc->id)->where('status', 'open')->exists()) $this->fail("A count is already open for {$loc->name}.");
        return StockCount::create(['location_id' => $loc->id, 'note' => $note, 'created_by' => $actor]);
    }

    /** counts: [{product_id, counted}] — mode add (scanner: +1 per scan) or set (typed total). */
    public function recordCount(int $countId, array $counts, string $mode = 'set'): StockCount {
        $c = StockCount::findOrFail($countId);
        if ($c->status !== 'open') $this->fail('This count is closed.');
        $ids = Product::whereIn('id', array_column($counts, 'product_id'))->pluck('id')->all();
        DB::transaction(function () use ($c, $counts, $mode, $ids) {
            foreach ($counts as $r) {
                $pid = (int) ($r['product_id'] ?? 0); $n = (int) ($r['counted'] ?? 0);
                if (!in_array($pid, $ids, true) || $n < 0) continue;
                $line = StockCountLine::where('stock_count_id', $c->id)->where('product_id', $pid)->lockForUpdate()->first() ?? new StockCountLine(['stock_count_id' => $c->id, 'product_id' => $pid, 'counted_qty' => 0]);
                $line->counted_qty = $mode === 'add' ? (int) $line->counted_qty + $n : $n; $line->save();
            }
        });
        return $c;
    }

    /** Approve: system qty is snapshotted now and the difference is booked as a recount adjustment.  zeroMissing = products not counted are set to 0. */
    public function approveCount(int $countId, bool $zeroMissing, int $actor): StockCount {
        return $this->transaction(function () use ($countId, $zeroMissing, $actor) {
            $c = StockCount::lockForUpdate()->findOrFail($countId);
            if ($c->status !== 'open') $this->fail('This count is already closed.');
            $loc = Location::findOrFail($c->location_id);
            $lines = StockCountLine::where('stock_count_id', $c->id)->get()->keyBy('product_id');
            $sys = self::quantities($loc);
            if ($zeroMissing) foreach ($sys as $pid => $q) if ($q > 0 && !$lines->has($pid)) $lines[$pid] = StockCountLine::create(['stock_count_id' => $c->id, 'product_id' => $pid, 'counted_qty' => 0]);
            foreach ($lines as $pid => $l) {
                $system = (int) ($sys[$pid] ?? 0); $diff = (int) $l->counted_qty - $system;
                $l->system_qty = $system; $l->save();
                if ($diff === 0) continue;
                $this->move($this->stock((int) $pid), 'available_stock', $diff, 'recount', "COUNT:$c->id", $actor);
                $this->bump($loc, (int) $pid, $diff);
            }
            $c->forceFill(['status' => 'approved', 'approved_by' => $actor, 'approved_at' => now()])->save();
            return $c;
        });
    }
}
