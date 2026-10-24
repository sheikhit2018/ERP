<?php
namespace App\Services;
use App\Models\{CourierBatch, ProductStyle, StockReceipt, Product, RawMaterial, BomItem, Bom, RawMaterialStock, FinishedGoodStock, ManufacturingOrder, ManufacturingConsumption,
    SalesOrder, SalesOrderItem, Shipment, CourierReturn, ReturnItem, CourierLog, InventoryMovement};
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\ValidationException;

/** All stock and catalog/BOM writers must acquire the tenant mutex first. */
class InventoryService {
    private function transaction(callable $fn): mixed {
        return DB::transaction(function () use ($fn) {
            $tenant = DB::table('tenants')->where('id', app(TenantContext::class)->id())->lockForUpdate()->first();
            abort_unless($tenant && $tenant->active, 403, 'Inactive tenant.');
            return $fn();
        }, 5);
    }
    private function fail(string $message): never {
        throw ValidationException::withMessages(['inventory' => [$message]]);
    }
    /** Normalise scanned items [{product_id, quantity}] to [product_id => qty]; null = no scan verification. */
    private function scanMap(mixed $scans): ?array {
        if ($scans === null) return null;
        Validator::make(['scans'=>$scans], ['scans'=>'array|max:500', 'scans.*.product_id'=>'required|integer|min:1', 'scans.*.quantity'=>'required|integer|min:1|max:1000000'])->validate();
        $map = [];
        foreach ($scans as $row) $map[(int)$row['product_id']] = ($map[(int)$row['product_id']] ?? 0) + (int)$row['quantity'];
        return $map;
    }
    private function assertScansMatch(array $expected, array $scanned): void {
        ksort($expected); ksort($scanned);
        if ($expected === $scanned) return;
        $names = Product::whereIn('id', array_unique(array_merge(array_keys($expected), array_keys($scanned))))->get()->keyBy('id');
        $diff = [];
        foreach ($expected + $scanned as $pid => $_) {
            $want = $expected[$pid] ?? 0; $got = $scanned[$pid] ?? 0;
            if ($want !== $got) $diff[] = ($names[$pid]->label ?? "#$pid").": need $want, scanned $got";
        }
        $this->fail('Scanned items do not match the order. '.implode('; ', $diff));
    }
    private function hash(array $data): string { return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)); }
    private function replay($model, string $hash) {
        abort_unless(hash_equals($model->request_hash, $hash), 409, 'Idempotency key reused with a different request.');
        return $model;
    }
    private function stock(int $id): FinishedGoodStock {
        $stock = FinishedGoodStock::where('product_id', $id)->lockForUpdate()->first();
        if (!$stock) $this->fail("No finished-good stock row for product $id.");
        return $stock;
    }
    private function movement(?int $product, ?int $material, string $bucket, int $delta, string $reason, string $reference, int $actor): void {
        InventoryMovement::create(['product_id'=>$product, 'raw_material_id'=>$material,
            'bucket'=>$bucket, 'delta'=>$delta, 'reason'=>$reason, 'reference'=>$reference, 'actor_id'=>$actor]);
    }
    private function move(FinishedGoodStock $s, string $bucket, int $delta, string $reason, string $ref, int $actor): void {
        if (!in_array($bucket, ['available_stock','reserved_stock','transit_stock','damaged_stock'], true)) throw new \LogicException('Invalid bucket');
        $current = (int)$s->$bucket;
        if (($delta < 0 && $current < -$delta) || ($delta > 0 && $current > PHP_INT_MAX - $delta)) $this->fail("Insufficient/overflowing $bucket for product {$s->product_id}.");
        $s->$bucket = $current + $delta; $s->save();
        $this->movement((int)$s->product_id, null, $bucket, $delta, $reason, $ref, $actor);
    }
    public function manufacture(array $data, int $actor): ManufacturingOrder {
        $d = Validator::make($data, ['bom_id'=>'required|integer|min:1', 'quantity'=>'required|integer|min:1|max:1000000000', 'idempotency_key'=>'required|uuid'])->validate();
        $d = ['bom_id'=>(int)$d['bom_id'], 'quantity'=>(int)$d['quantity'], 'idempotency_key'=>$d['idempotency_key']];
        return $this->transaction(function () use ($d, $actor) {
            $hash = $this->hash($d);
            $old = ManufacturingOrder::where('idempotency_key', $d['idempotency_key'])->first();
            if ($old) return $this->replay($old, $hash);
            $bom = Bom::whereKey($d['bom_id'])->lockForUpdate()->firstOrFail();
            if (!$bom->active) $this->fail('BOM is inactive.');
            $items = $bom->items()->orderBy('raw_material_id')->lockForUpdate()->get();
            if ($items->isEmpty()) $this->fail('BOM has no materials.');
            $needs = [];
            foreach ($items as $item) {
                $perUnit = (int)$item->quantity_per_unit;
                if ($perUnit <= 0 || $perUnit > intdiv(PHP_INT_MAX, $d['quantity'])) $this->fail('Invalid or overflowing BOM requirement.');
                $required = $perUnit * $d['quantity'];
                $stock = RawMaterialStock::where('raw_material_id', $item->raw_material_id)->lockForUpdate()->first();
                if (!$stock || (int)$stock->quantity < $required) $this->fail("Raw material {$item->raw_material_id} requires $required base units; available ".($stock?->quantity ?? 0).'.');
                $needs[] = [$stock, $required];
            }
            $fg = $this->stock((int)$bom->product_id);
            $mo = ManufacturingOrder::create($d + ['request_hash'=>$hash, 'status'=>'completed', 'created_by'=>$actor, 'completed_at'=>now()]);
            foreach ($needs as [$stock, $required]) {
                $stock->quantity = (int)$stock->quantity - $required; $stock->save();
                ManufacturingConsumption::create(['manufacturing_order_id'=>$mo->id, 'raw_material_id'=>$stock->raw_material_id, 'quantity'=>$required]);
                $this->movement(null, (int)$stock->raw_material_id, 'raw', -$required, 'manufacture', "MO:$mo->id", $actor);
            }
            $this->move($fg, 'available_stock', $d['quantity'], 'manufacture', "MO:$mo->id", $actor);
            return $mo;
        });
    }
    public function place(array $data, int $actor): SalesOrder {
        $d = Validator::make($data, [
            'idempotency_key'=>'required|uuid', 'status'=>'required|in:pending,confirmed',
            'customer'=>'required|array:name,phone,address', 'customer.name'=>'required|string|max:150',
            'customer.phone'=>'required|string|max:40', 'customer.address'=>'required|string|max:1000',
            'currency'=>'required|in:BDT,USD', 'items'=>'required|array|min:1|max:100',
            'items.*'=>'required|array:product_id,quantity,unit_price_minor',
            'items.*.product_id'=>'required|integer|min:1|distinct',
            'items.*.quantity'=>'required|integer|min:1|max:1000000000',
            'items.*.unit_price_minor'=>'required|integer|min:0|max:1000000000',
        ])->validate();
        usort($d['items'], fn($a,$b) => $a['product_id'] <=> $b['product_id']);
        foreach ($d['items'] as &$item) $item = array_map('intval', $item);
        unset($item);
        // Fix key order so equivalent JSON requests have the same hash.
        $d['customer'] = ['name'=>$d['customer']['name'], 'phone'=>$d['customer']['phone'], 'address'=>$d['customer']['address']];
        $hashData = [$d['status'], $d['customer'], $d['currency'], array_map(fn($i)=>[$i['product_id'],$i['quantity'],$i['unit_price_minor']], $d['items'])];
        return $this->transaction(function () use ($d, $actor, $hashData) {
            $hash = $this->hash($hashData);
            $old = SalesOrder::where('idempotency_key', $d['idempotency_key'])->first();
            if ($old) return $this->replay($old, $hash);
            $order = SalesOrder::create(['idempotency_key'=>$d['idempotency_key'], 'request_hash'=>$hash,
                'status'=>$d['status'], 'customer'=>$d['customer'], 'currency'=>$d['currency'], 'created_by'=>$actor,
                'customer_phone'=>\App\Support\Phone::normalize($d['customer']['phone'])]);
            foreach ($d['items'] as $item) {
                $s = $this->stock($item['product_id']);
                SalesOrderItem::create($item + ['sales_order_id'=>$order->id]);
                $this->move($s, 'available_stock', -$item['quantity'], 'reserve', "ORDER:$order->id", $actor);
                $this->move($s, 'reserved_stock', $item['quantity'], 'reserve', "ORDER:$order->id", $actor);
            }
            return $order;
        });
    }
    public function confirm(int $id): SalesOrder {
        return $this->transaction(function () use ($id) {
            $o = SalesOrder::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($o->status === 'confirmed') return $o;
            abort_unless($o->status === 'pending', 409, 'Only pending orders can be confirmed.');
            $o->status = 'confirmed'; $o->save(); return $o; // reservation already exists
        });
    }
    public function cancel(int $id, int $actor): SalesOrder {
        return $this->transaction(function () use ($id, $actor) {
            $o = SalesOrder::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($o->status === 'cancelled') return $o;
            abort_unless(in_array($o->status, ['pending','confirmed','packed'], true), 409, 'Cannot cancel after handover.');
            foreach ($o->items()->orderBy('product_id')->get() as $i) {
                $s = $this->stock((int)$i->product_id);
                $this->move($s, 'reserved_stock', -(int)$i->quantity, 'cancel', "ORDER:$id", $actor);
                $this->move($s, 'available_stock', (int)$i->quantity, 'cancel', "ORDER:$id", $actor);
            }
            $o->status = 'cancelled'; $o->packed_at = null; $o->save(); return $o;
        });
    }
    private function log(Shipment $s, string $event, int $actor, array $payload = []): void {
        CourierLog::create(['shipment_id'=>$s->id, 'event'=>$event, 'payload'=>$payload, 'actor_id'=>$actor]);
    }
    public function handover(int $id, array $data, int $actor, ?int $batchId = null): SalesOrder {
        $d = Validator::make($data, ['courier'=>'required|string|max:40|regex:/^[a-z0-9_-]+$/', 'tracking_id'=>'required|string|max:120'])->validate();
        $scans = $this->scanMap($data['scans'] ?? null);
        $d = ['courier'=>$d['courier'], 'tracking_id'=>trim($d['tracking_id'])];
        if ($d['tracking_id'] === '') $this->fail('Tracking ID is required.');
        return $this->transaction(function () use ($id, $d, $actor, $scans, $batchId) {
            $o = SalesOrder::whereKey($id)->lockForUpdate()->firstOrFail();
            $old = $o->shipment()->first();
            if ($old) {
                abort_unless($old->courier === $d['courier'] && $old->tracking_id === $d['tracking_id'], 409, 'Order already handed over with different shipment data.');
                return $o;
            }
            // A packed order was already scan-verified at the packing station; a confirmed one must be scanned now.
            abort_unless($o->status === 'packed' || ($o->status === 'confirmed' && $scans !== null), 409,
                $o->status === 'confirmed' ? 'Pack the order first (scan every piece at the packing station).' : 'Only packed orders can be handed over.');
            if ($scans !== null) $this->assertScansMatch($o->items()->get()->mapWithKeys(fn ($i) => [(int)$i->product_id => (int)$i->quantity])->all(), $scans);
            if ($o->status === 'confirmed') { $o->packed_at = now(); $o->packed_by = $actor; }
            abort_if(Shipment::where('courier',$d['courier'])->where('tracking_id',$d['tracking_id'])->exists(), 409, 'Tracking ID already assigned.');
            $s = Shipment::create($d + ['sales_order_id'=>$id, 'status'=>'shipped', 'handed_over_at'=>now(),
                'piece_count'=>(int) $o->items()->sum('quantity'), 'dispatch_batch_id'=>$batchId, 'cod_expected_minor'=>$o->codDueMinor()]);
            foreach ($o->items()->orderBy('product_id')->get() as $i) {
                $stock = $this->stock((int)$i->product_id);
                $this->move($stock, 'reserved_stock', -(int)$i->quantity, 'handover', "SHIPMENT:$s->id", $actor);
                $this->move($stock, 'transit_stock', (int)$i->quantity, 'handover', "SHIPMENT:$s->id", $actor);
            }
            $o->status = 'shipped'; $o->save(); $this->log($s, 'handed_over', $actor, $scans !== null ? ['scan_verified'=>true] : []);
            return $o;
        });
    }
    public function delivered(int $id, int $actor): SalesOrder {
        return $this->transaction(function () use ($id, $actor) {
            $o = SalesOrder::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($o->status === 'delivered') return $o;
            abort_unless($o->status === 'shipped', 409, 'Only shipped orders can be delivered.');
            $s = $o->shipment()->firstOrFail();
            foreach ($o->items()->orderBy('product_id')->get() as $i) {
                $this->move($this->stock((int)$i->product_id), 'transit_stock', -(int)$i->quantity, 'delivered', "SHIPMENT:$s->id", $actor);
            }
            $s->status = 'delivered'; $s->delivered_at = now(); $s->save(); $o->status = 'delivered'; $o->save();
            $this->log($s, 'delivered', $actor); return $o;
        });
    }
    public function scan(array $data, int $actor): CourierReturn {
        $d = Validator::make($data, ['courier_tracking_id'=>'required|string|max:120', 'courier'=>'nullable|string|max:40'])->validate();
        return $this->transaction(function () use ($d, $actor) {
            $matches = Shipment::where('tracking_id', trim($d['courier_tracking_id']))
                ->when(!empty($d['courier']), fn($q) => $q->where('courier', $d['courier']))->limit(2)->get();
            abort_if($matches->isEmpty(), 404, 'Tracking ID not found in this tenant.');
            abort_if($matches->count() > 1, 409, 'Ambiguous tracking ID; provide courier.');
            $s = $matches->first();
            $old = CourierReturn::where('shipment_id', $s->id)->first();
            if ($old) return $old;
            $o = $s->order()->lockForUpdate()->firstOrFail();
            abort_unless($o->status === 'shipped' && $s->status === 'shipped', 409, 'This shipment is not eligible for courier return.');
            $r = CourierReturn::create(['shipment_id'=>$s->id, 'status'=>'pending', 'received_by'=>$actor]);
            foreach ($o->items as $i) ReturnItem::create(['courier_return_id'=>$r->id, 'sales_order_item_id'=>$i->id, 'quantity'=>$i->quantity]);
            $o->status = 'return_pending'; $o->save(); $s->status = 'return_pending'; $s->returned_at = now(); $s->returned_pieces = (int) $s->piece_count; $s->save();
            $this->log($s, 'return_scanned', $actor);
            // Logical transit includes unapproved returns. These units remain unsellable until QC.
            return $r;
        });
    }
    public function qc(int $id, string $decision, int $actor, ?array $scansInput = null): CourierReturn {
        Validator::make(['decision'=>$decision], ['decision'=>'required|in:QC_PASSED,DAMAGED'])->validate();
        $scans = $this->scanMap($scansInput);
        return $this->transaction(function () use ($id, $decision, $actor, $scans) {
            $r = CourierReturn::whereKey($id)->lockForUpdate()->firstOrFail();
            $target = $decision === 'QC_PASSED' ? 'passed' : 'damaged';
            if ($r->status === $target) return $r;
            abort_unless($r->status === 'pending', 409, 'QC already finalized with another decision.');
            $s = $r->shipment()->firstOrFail(); $o = $s->order()->lockForUpdate()->firstOrFail();
            abort_unless($o->status === 'return_pending' && $s->status === 'return_pending', 409, 'Invalid return state.');
            if ($scans !== null) {
                $expected = [];
                foreach ($r->items()->with('orderItem')->get() as $i) $expected[(int)$i->orderItem->product_id] = ($expected[(int)$i->orderItem->product_id] ?? 0) + (int)$i->quantity;
                $this->assertScansMatch($expected, $scans);
            }
            foreach ($r->items()->with('orderItem')->orderBy('sales_order_item_id')->get() as $i) {
                $stock = $this->stock((int)$i->orderItem->product_id);
                $this->move($stock, 'transit_stock', -(int)$i->quantity, 'return_qc', "RETURN:$id", $actor);
                $bucket = $target === 'passed' ? 'available_stock' : 'damaged_stock';
                $this->move($stock, $bucket, (int)$i->quantity, 'return_qc', "RETURN:$id", $actor);
            }
            if ($target === 'damaged') $r->items()->update(['damaged_quantity'=>DB::raw('quantity')]);
            $r->status = $target; $r->reviewed_by = $actor; $r->reviewed_at = now(); $r->save();
            $o->status = $target === 'passed' ? 'returned' : 'return_damaged'; $o->save();
            $s->status = $o->status; $s->save(); $this->log($s, $decision, $actor, $scans !== null ? ['scan_verified'=>true] : []);
            return $r;
        });
    }

    /* ------------------------------------------------------------------
     | Catalog + stock-opening writers (Opryx phase 1).
     | They honour the same tenant mutex as every other stock writer and
     | record every balance change in inventory_movements.
     * ------------------------------------------------------------------ */
    public function createProduct(array $data, int $actor): Product {
        $d = Validator::make($data, [
            'sku'=>'required|string|max:80|regex:/^[A-Za-z0-9._-]+$/', 'name'=>'required|string|max:190',
            'base_unit'=>'nullable|string|max:20', 'price_minor'=>'required|integer|min:0|max:100000000000',
            'cost_minor'=>'nullable|integer|min:0|max:100000000000', 'low_stock_level'=>'nullable|integer|min:0|max:1000000000',
            'opening_stock'=>'nullable|integer|min:0|max:1000000000',
        ])->validate();
        return $this->transaction(function () use ($d, $actor) {
            if (Product::where('sku', $d['sku'])->exists()) $this->fail("SKU {$d['sku']} already exists.");
            $p = Product::create(['sku'=>$d['sku'], 'name'=>$d['name'], 'base_unit'=>$d['base_unit'] ?? 'piece',
                'price_minor'=>(int)$d['price_minor'], 'cost_minor'=>(int)($d['cost_minor'] ?? 0),
                'low_stock_level'=>(int)($d['low_stock_level'] ?? 5)]);
            $p->barcode = $this->nextBarcode(); $p->save();
            $stock = FinishedGoodStock::create(['product_id'=>$p->id]);
            if (!empty($d['opening_stock'])) $this->move($stock, 'available_stock', (int)$d['opening_stock'], 'opening', "PRODUCT:$p->id", $actor);
            return $p;
        });
    }
    public function updateProduct(int $id, array $data): Product {
        $d = Validator::make($data, [
            'name'=>'required|string|max:190', 'price_minor'=>'required|integer|min:0|max:100000000000',
            'cost_minor'=>'nullable|integer|min:0|max:100000000000', 'low_stock_level'=>'nullable|integer|min:0|max:1000000000',
            'active'=>'boolean',
        ])->validate();
        return $this->transaction(function () use ($id, $d) {
            $p = Product::whereKey($id)->lockForUpdate()->firstOrFail();
            $p->fill(['name'=>$d['name'], 'price_minor'=>(int)$d['price_minor'], 'cost_minor'=>(int)($d['cost_minor'] ?? 0),
                'low_stock_level'=>(int)($d['low_stock_level'] ?? 0), 'active'=>(bool)($d['active'] ?? true)])->save();
            return $p;
        });
    }
    /** Manual stock in/out for finished goods: purchase receipt, recount, loss. */
    public function adjustProduct(int $id, array $data, int $actor): FinishedGoodStock {
        $d = Validator::make($data, ['delta'=>'required|integer|not_in:0|min:-1000000000|max:1000000000',
            'reason'=>'required|in:purchase,recount,damage_writeoff,opening,other', 'note'=>'nullable|string|max:60'])->validate();
        return $this->transaction(function () use ($id, $d, $actor) {
            $s = $this->stock($id);
            $ref = 'ADJ'.(!empty($d['note']) ? ':'.$d['note'] : '');
            $this->move($s, 'available_stock', (int)$d['delta'], $d['reason'], substr($ref, 0, 100), $actor);
            return $s;
        });
    }
    public function createRawMaterial(array $data, int $actor): RawMaterial {
        $d = Validator::make($data, [
            'sku'=>'required|string|max:80|regex:/^[A-Za-z0-9._-]+$/', 'name'=>'required|string|max:190',
            'base_unit'=>'required|in:g,ml,mm,cm,piece', 'low_stock_level'=>'nullable|integer|min:0',
            'opening_stock'=>'nullable|integer|min:0|max:1000000000000',
        ])->validate();
        return $this->transaction(function () use ($d, $actor) {
            if (RawMaterial::where('sku', $d['sku'])->exists()) $this->fail("SKU {$d['sku']} already exists.");
            $m = RawMaterial::create(['sku'=>$d['sku'], 'name'=>$d['name'], 'base_unit'=>$d['base_unit'], 'low_stock_level'=>(int)($d['low_stock_level'] ?? 0)]);
            $s = RawMaterialStock::create(['raw_material_id'=>$m->id, 'quantity'=>(int)($d['opening_stock'] ?? 0)]);
            if ($s->quantity > 0) $this->movement(null, $m->id, 'raw', (int)$s->quantity, 'opening', "MATERIAL:$m->id", $actor);
            return $m;
        });
    }
    public function adjustRawMaterial(int $id, array $data, int $actor): RawMaterialStock {
        $d = Validator::make($data, ['delta'=>'required|integer|not_in:0|min:-1000000000000|max:1000000000000',
            'reason'=>'required|in:purchase,recount,damage_writeoff,opening,other'])->validate();
        return $this->transaction(function () use ($id, $d, $actor) {
            $s = RawMaterialStock::where('raw_material_id', $id)->lockForUpdate()->first();
            if (!$s) $this->fail('No stock row for this material.');
            $new = (int)$s->quantity + (int)$d['delta'];
            if ($new < 0) $this->fail('Stock cannot go below zero.');
            $s->quantity = $new; $s->save();
            $this->movement(null, $id, 'raw', (int)$d['delta'], $d['reason'], 'ADJ', $actor);
            return $s;
        });
    }
    /** BOMs are immutable once created: a change is a new version; older versions are deactivated. */
    public function createBom(array $data): Bom {
        $d = Validator::make($data, [
            'product_id'=>'required|integer|min:1', 'items'=>'required|array|min:1|max:100',
            'items.*.raw_material_id'=>'required|integer|min:1|distinct', 'items.*.quantity_per_unit'=>'required|integer|min:1|max:1000000000',
        ])->validate();
        return $this->transaction(function () use ($d) {
            $product = Product::whereKey($d['product_id'])->firstOrFail();
            foreach ($d['items'] as $i) if (!RawMaterial::whereKey($i['raw_material_id'])->exists()) $this->fail('Unknown raw material.');
            $version = (int)Bom::where('product_id', $product->id)->max('version') + 1;
            Bom::where('product_id', $product->id)->update(['active'=>false]);
            $bom = Bom::create(['product_id'=>$product->id, 'version'=>$version, 'active'=>true]);
            foreach ($d['items'] as $i) BomItem::create(['bom_id'=>$bom->id, 'raw_material_id'=>(int)$i['raw_material_id'], 'quantity_per_unit'=>(int)$i['quantity_per_unit']]);
            return $bom;
        });
    }

    /* ------------------------------------------------------------------
     | Colour / size variants, barcodes and scanned stock-in.
     * ------------------------------------------------------------------ */

    /** Next unique in-store EAN-13 for this tenant. Call only inside transaction() (tenant row is locked). */
    private function nextBarcode(): string {
        $tenant = app(TenantContext::class)->id();
        $prefix = '2'.str_pad((string) ($tenant % 1000), 3, '0', STR_PAD_LEFT);
        $max = 0;
        foreach ([Product::query(), ProductStyle::query()] as $q) {
            $b = $q->where('barcode', 'like', $prefix.'%')->whereRaw('length(barcode) = 13')->max('barcode');
            if ($b) $max = max($max, (int) substr($b, 4, 8));
        }
        $body = $prefix.str_pad((string) ($max + 1), 8, '0', STR_PAD_LEFT);
        $sum = 0;
        foreach (str_split($body) as $i => $digit) $sum += (int) $digit * ($i % 2 ? 3 : 1);
        return $body.((10 - $sum % 10) % 10);
    }

    private static function cleanList(array $items, int $max): array {
        $out = [];
        foreach ($items as $v) {
            $v = trim(preg_replace('/\s+/', ' ', (string) $v));
            if ($v !== '' && !in_array(mb_strtolower($v), array_map('mb_strtolower', $out), true)) $out[] = mb_substr($v, 0, $max);
        }
        return $out;
    }
    private static function skuPart(string $v): string {
        $v = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $v));
        return $v !== '' ? substr($v, 0, 12) : 'X';
    }

    /** Create a style (design) and one variant per colour x size, each with its own barcode and stock row. */
    public function createStyle(array $data, int $actor): ProductStyle {
        $d = Validator::make($data, [
            'code'=>'required|string|max:40|regex:/^[A-Za-z0-9._-]+$/', 'name'=>'required|string|max:150',
            'price_minor'=>'required|integer|min:0|max:100000000000', 'cost_minor'=>'nullable|integer|min:0|max:100000000000',
            'low_stock_level'=>'nullable|integer|min:0|max:1000000000',
            'colors'=>'required|array|min:1|max:40', 'colors.*'=>'required|string|max:40',
            'sizes'=>'required|array|min:1|max:40', 'sizes.*'=>'required|string|max:20',
            'barcode'=>'nullable|string|max:64|regex:/^[A-Za-z0-9-]+$/',
        ] + self::QTY_RULES)->validate();
        $colors = self::cleanList($d['colors'], 40); $sizes = self::cleanList($d['sizes'], 20);
        if (!$colors || !$sizes) $this->fail('Add at least one colour and one size.');
        return $this->transaction(function () use ($d, $colors, $sizes, $actor) {
            $code = strtoupper($d['code']);
            if (ProductStyle::where('code', $code)->exists()) $this->fail("Style code $code already exists.");
            $barcode = !empty($d['barcode']) ? $d['barcode'] : $this->nextBarcode();
            $this->assertBarcodeFree($barcode);
            $style = ProductStyle::create(['code'=>$code, 'name'=>$d['name'], 'barcode'=>$barcode]);
            $made = $this->makeVariants($style, $colors, $sizes, (int) $d['price_minor'], (int) ($d['cost_minor'] ?? 0), (int) ($d['low_stock_level'] ?? 5));
            $this->openingStock($style, $made, $d['quantities'] ?? [], $actor);
            return $style;
        });
    }

    /** Add more colours and/or sizes to an existing style; existing combinations are skipped. */
    public function addVariants(int $styleId, array $data, int $actor = 0): int {
        $d = Validator::make($data, [
            'colors'=>'required|array|min:1|max:40', 'colors.*'=>'required|string|max:40',
            'sizes'=>'required|array|min:1|max:40', 'sizes.*'=>'required|string|max:20',
            'price_minor'=>'nullable|integer|min:0|max:100000000000',
        ] + self::QTY_RULES)->validate();
        if (!empty($d['quantities']) && $actor < 1) $this->fail('Opening stock needs a signed-in user.');
        return $this->transaction(function () use ($styleId, $d, $actor) {
            $style = ProductStyle::whereKey($styleId)->firstOrFail();
            $ref = Product::where('style_id', $style->id)->orderBy('id')->first();
            $made = $this->makeVariants($style, self::cleanList($d['colors'], 40), self::cleanList($d['sizes'], 20),
                (int) ($d['price_minor'] ?? $ref?->price_minor ?? 0), (int) ($ref?->cost_minor ?? 0), (int) ($ref?->low_stock_level ?? 5));
            $this->openingStock($style, $made, $d['quantities'] ?? [], $actor);
            return count($made);
        });
    }

    /** @return Product[] the variants that were actually created (existing combinations are skipped) */
    private function makeVariants(ProductStyle $style, array $colors, array $sizes, int $price, int $cost, int $low): array {
        $made = [];
        foreach ($colors as $color) foreach ($sizes as $size) {
            $exists = Product::where('style_id', $style->id)->where('color', $color)->where('size', $size)->exists();
            if ($exists) continue;
            $sku = $style->code.'-'.self::skuPart($color).'-'.self::skuPart($size);
            if (Product::where('sku', $sku)->exists()) $sku .= '-'.substr(md5($color.$size), 0, 4);
            $p = Product::create(['sku'=>$sku, 'name'=>$style->name, 'base_unit'=>'piece', 'style_id'=>$style->id,
                'color'=>$color, 'size'=>$size, 'barcode'=>$this->nextBarcode(),
                'price_minor'=>$price, 'cost_minor'=>$cost, 'low_stock_level'=>$low]);
            FinishedGoodStock::create(['product_id'=>$p->id]);
            $made[] = $p;
        }
        return $made;
    }

    /** Optional opening quantity per colour x size, e.g. [{color:"White", size:"S", quantity:1000}]. */
    private const QTY_RULES = [
        'quantities'=>'nullable|array|max:1600', 'quantities.*.color'=>'required|string|max:40',
        'quantities.*.size'=>'required|string|max:20', 'quantities.*.quantity'=>'required|integer|min:0|max:10000000',
    ];

    /**
     * Book the opening quantities of freshly created variants as ONE stock receipt (reason "opening"),
     * so they show in Stock In history and the stock ledger exactly like a scanned receipt.
     * Only variants created in this request get stock, so a repeated "add colour" can never double-count.
     */
    private function openingStock(ProductStyle $style, array $made, array $quantities, int $actor): ?StockReceipt {
        $key = fn ($c, $s) => mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $c))).'|'.mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $s)));
        $wanted = [];
        foreach ($quantities as $q) if ((int) $q['quantity'] > 0) $wanted[$key($q['color'], $q['size'])] = (int) $q['quantity'];
        $lines = [];
        foreach ($made as $p) if (isset($wanted[$key($p->color, $p->size)])) $lines[$p->id] = $wanted[$key($p->color, $p->size)];
        if (!$lines) return null;
        ksort($lines);
        $receipt = StockReceipt::create(['idempotency_key'=>(string) \Illuminate\Support\Str::uuid(), 'request_hash'=>$this->hash(['opening', $style->id, $lines]),
            'reason'=>'opening', 'note'=>mb_substr("Opening stock: {$style->name} ({$style->code})", 0, 190),
            'total_quantity'=>array_sum($lines), 'line_count'=>count($lines), 'created_by'=>$actor]);
        foreach ($lines as $pid => $qty) $this->move($this->stock($pid), 'available_stock', $qty, 'stock_in_opening', "RECEIPT:$receipt->id", $actor);
        return $receipt;
    }

    private function assertBarcodeFree(string $barcode, ?int $exceptProduct = null): void {
        $p = Product::where('barcode', $barcode)->when($exceptProduct, fn ($q) => $q->whereKeyNot($exceptProduct))->exists();
        if ($p || ProductStyle::where('barcode', $barcode)->exists()) $this->fail("Barcode $barcode is already used.");
    }

    /** Use the manufacturer's own barcode for one variant instead of the generated one. */
    public function setBarcode(int $productId, string $barcode): Product {
        Validator::make(['barcode'=>$barcode], ['barcode'=>'required|string|max:64|regex:/^[A-Za-z0-9-]+$/'])->validate();
        return $this->transaction(function () use ($productId, $barcode) {
            $p = Product::whereKey($productId)->firstOrFail();
            $this->assertBarcodeFree($barcode, $p->id);
            $p->barcode = $barcode; $p->save();
            return $p;
        });
    }

    /**
     * Scanned Stock In (goods receipt): many variants in one batch, all-or-nothing, idempotent.
     * lines: [{product_id, quantity}], reason: purchase|production|return|opening|recount
     */
    public function receiveStock(array $data, int $actor): StockReceipt {
        $d = Validator::make($data, [
            'idempotency_key'=>'required|uuid', 'reason'=>'required|in:purchase,production,return,opening,recount',
            'note'=>'nullable|string|max:190', 'lines'=>'required|array|min:1|max:1000',
            'lines.*.product_id'=>'required|integer|min:1', 'lines.*.quantity'=>'required|integer|min:1|max:10000000',
        ])->validate();
        $lines = [];
        foreach ($d['lines'] as $l) $lines[(int) $l['product_id']] = ($lines[(int) $l['product_id']] ?? 0) + (int) $l['quantity'];
        ksort($lines);
        $hash = $this->hash([$d['reason'], $d['note'] ?? null, $lines]);
        return $this->transaction(function () use ($d, $lines, $hash, $actor) {
            $old = StockReceipt::where('idempotency_key', $d['idempotency_key'])->first();
            if ($old) return $this->replay($old, $hash);
            $receipt = StockReceipt::create(['idempotency_key'=>$d['idempotency_key'], 'request_hash'=>$hash, 'reason'=>$d['reason'],
                'note'=>$d['note'] ?? null, 'total_quantity'=>array_sum($lines), 'line_count'=>count($lines), 'created_by'=>$actor]);
            foreach ($lines as $pid => $qty) {
                $this->move($this->stock($pid), 'available_stock', $qty, 'stock_in_'.$d['reason'], "RECEIPT:$receipt->id", $actor);
            }
            return $receipt;
        });
    }

    /* ------------------------------------------------------------------
     | Courier batches: bulk handover, bulk return receipt, delivered, lost.
     | Every parcel is a shipment; a batch only groups them and freezes the
     | parcel / piece counts the courier signed for.
     * ------------------------------------------------------------------ */

    private const COURIER_RULE = 'required|string|max:40|regex:/^[a-z0-9_-]+$/';

    /** Shipments by tracking id for one courier, keyed by tracking id. */
    private function shipmentsByTracking(string $courier, array $trackings) {
        return Shipment::where('courier', $courier)->whereIn('tracking_id', $trackings)->get()->keyBy('tracking_id');
    }

    /**
     * Hand many confirmed orders to one courier at once.
     * parcels: [{order_id, tracking_id}]. All-or-nothing: any problem rejects the whole batch with a full list.
     */
    public function dispatchBatch(array $data, int $actor): CourierBatch {
        $d = Validator::make($data, [
            'idempotency_key'=>'required|uuid', 'courier'=>self::COURIER_RULE, 'note'=>'nullable|string|max:190',
            'parcels'=>'required|array|min:1|max:2000',
            'parcels.*.order_id'=>'required|integer|min:1|distinct', 'parcels.*.tracking_id'=>'required|string|max:120',
        ])->validate();
        $parcels = [];
        foreach ($d['parcels'] as $p) $parcels[(int) $p['order_id']] = trim((string) $p['tracking_id']);
        ksort($parcels);
        $dupe = array_diff_assoc($parcels, array_unique($parcels));
        if ($dupe) $this->fail('Same tracking ID on more than one parcel: '.implode(', ', array_unique($dupe)));
        if (in_array('', $parcels, true)) $this->fail('Every parcel needs a tracking ID.');
        $hash = $this->hash(['dispatch', $d['courier'], $d['note'] ?? null, $parcels]);

        return $this->transaction(function () use ($d, $parcels, $hash, $actor) {
            $old = CourierBatch::where('idempotency_key', $d['idempotency_key'])->first();
            if ($old) return $this->replay($old, $hash);

            $orders = SalesOrder::whereIn('id', array_keys($parcels))->lockForUpdate()->get()->keyBy('id');
            $taken = $this->shipmentsByTracking($d['courier'], array_values($parcels));
            $problems = [];
            foreach ($parcels as $oid => $tracking) {
                $o = $orders[$oid] ?? null;
                if (!$o) $problems[] = "#$oid not found";
                elseif ($o->status !== 'packed') $problems[] = $o->status === 'confirmed' ? "#$oid is not packed yet (scan it at the packing station)" : "#$oid is {$o->status}";
                if (isset($taken[$tracking])) $problems[] = "tracking $tracking already used on order #{$taken[$tracking]->sales_order_id}";
            }
            if ($problems) $this->fail('Batch not saved. Fix: '.implode('; ', $problems).'.');

            $pieces = (int) SalesOrderItem::whereIn('sales_order_id', array_keys($parcels))->sum('quantity');
            $batch = CourierBatch::create(['type'=>'dispatch', 'courier'=>$d['courier'], 'parcel_count'=>count($parcels), 'piece_count'=>$pieces,
                'note'=>$d['note'] ?? null, 'idempotency_key'=>$d['idempotency_key'], 'request_hash'=>$hash, 'created_by'=>$actor]);
            foreach ($parcels as $oid => $tracking) {
                $this->handover($oid, ['courier'=>$d['courier'], 'tracking_id'=>$tracking], $actor, $batch->id);
            }
            return $batch;
        });
    }

    /**
     * Receive many returned parcels from one courier at once and put the pieces back in stock.
     * parcels: [{tracking_id, items: [{product_id, returned, damaged}], short: kept|missing}]
     *   items  = what was scanned out of the parcel per variant (returned includes damaged).
     *   short  = what happened to pieces that did not come back: "kept" by the customer (partial
     *            delivery) or "missing" (courier claim). Required when anything is short.
     * Without items, the whole parcel is taken as returned ({tracking_id, damaged: bool}).
     * A parcel earlier marked "lost" that turns up again is accepted too.
     */
    public function returnBatch(array $data, int $actor): CourierBatch {
        $d = Validator::make($data, [
            'idempotency_key'=>'required|uuid', 'courier'=>self::COURIER_RULE, 'note'=>'nullable|string|max:190',
            'parcels'=>'required|array|min:1|max:2000',
            'parcels.*.tracking_id'=>'required|string|max:120|distinct', 'parcels.*.damaged'=>'nullable|boolean',
            'parcels.*.short'=>'nullable|in:kept,missing',
            'parcels.*.items'=>'nullable|array|max:100', 'parcels.*.items.*.product_id'=>'required|integer|min:1',
            'parcels.*.items.*.returned'=>'required|integer|min:0|max:1000000', 'parcels.*.items.*.damaged'=>'nullable|integer|min:0|max:1000000',
        ])->validate();
        $parcels = [];
        foreach ($d['parcels'] as $p) {
            $items = null;
            if (isset($p['items'])) {
                $items = [];
                foreach ($p['items'] as $i) {
                    $pid = (int) $i['product_id'];
                    $items[$pid] = ['returned'=>($items[$pid]['returned'] ?? 0) + (int) $i['returned'], 'damaged'=>($items[$pid]['damaged'] ?? 0) + (int) ($i['damaged'] ?? 0)];
                }
                ksort($items);
            }
            $parcels[trim((string) $p['tracking_id'])] = ['items'=>$items, 'damaged'=>!empty($p['damaged']), 'short'=>$p['short'] ?? null];
        }
        ksort($parcels);
        $hash = $this->hash(['return', $d['courier'], $d['note'] ?? null, $parcels]);

        return $this->transaction(function () use ($d, $parcels, $hash, $actor) {
            $old = CourierBatch::where('idempotency_key', $d['idempotency_key'])->first();
            if ($old) return $this->replay($old, $hash);

            $ships = $this->shipmentsByTracking($d['courier'], array_keys($parcels));
            $lines = SalesOrderItem::whereIn('sales_order_id', $ships->pluck('sales_order_id'))->get()->groupBy('sales_order_id');
            $problems = []; $plan = [];
            foreach ($parcels as $tracking => $p) {
                $s = $ships[$tracking] ?? null;
                if (!$s) { $problems[] = "$tracking not found for {$d['courier']}"; continue; }
                if (!in_array($s->status, ['shipped', 'lost'], true)) { $problems[] = "$tracking (order #{$s->sales_order_id}) is already {$s->status}"; continue; }
                $rows = [];
                foreach ($lines[$s->sales_order_id] ?? [] as $l) {
                    $sent = (int) $l->quantity;
                    $back = $p['items'] === null ? $sent : (int) ($p['items'][(int) $l->product_id]['returned'] ?? 0);
                    $bad = $p['items'] === null ? ($p['damaged'] ? $sent : 0) : (int) ($p['items'][(int) $l->product_id]['damaged'] ?? 0);
                    if ($back > $sent) $problems[] = "$tracking: more pieces scanned than were sent";
                    if ($bad > $back) $problems[] = "$tracking: more damaged than returned";
                    $rows[] = ['line'=>$l, 'sent'=>$sent, 'back'=>min($back, $sent), 'bad'=>min($bad, $back)];
                }
                if ($p['items'] !== null) {
                    $foreign = array_diff(array_keys($p['items']), ($lines[$s->sales_order_id] ?? collect())->pluck('product_id')->map(fn ($x) => (int) $x)->all());
                    if ($foreign) $problems[] = "$tracking: scanned item(s) that were not in this parcel";
                }
                $short = array_sum(array_map(fn ($r) => $r['sent'] - $r['back'], $rows));
                if ($short > 0 && !$p['short']) $problems[] = "$tracking: $short pcs did not come back — choose “customer kept” or “missing”";
                $plan[$tracking] = ['ship'=>$s, 'rows'=>$rows, 'short'=>$p['short']];
            }
            if ($problems) $this->fail('Return batch not saved. Fix: '.implode('; ', array_unique($problems)).'.');

            $batch = CourierBatch::create(['type'=>'return', 'courier'=>$d['courier'], 'parcel_count'=>count($parcels),
                'piece_count'=>array_sum(array_map(fn ($x) => array_sum(array_column($x['rows'], 'back')), $plan)), 'note'=>$d['note'] ?? null,
                'idempotency_key'=>$d['idempotency_key'], 'request_hash'=>$hash, 'created_by'=>$actor]);
            foreach ($plan as $x) $this->receiveReturn($x['ship'], $x['rows'], $x['short'], $batch->id, $actor);
            return $batch;
        });
    }

    /** Book one returned parcel line by line. Call inside transaction(). */
    private function receiveReturn(Shipment $s, array $rows, ?string $short, int $batchId, int $actor): void {
        $s = Shipment::whereKey($s->id)->lockForUpdate()->first();
        if ($s->status === 'lost') $this->unLose($s, $actor);
        $o = $s->order()->lockForUpdate()->firstOrFail();
        $good = $bad = $kept = $missing = 0;
        $r = CourierReturn::create(['shipment_id'=>$s->id, 'status'=>'pending', 'received_by'=>$actor, 'return_batch_id'=>$batchId]);
        foreach ($rows as $x) {
            $l = $x['line']; $g = $x['back'] - $x['bad']; $sh = $x['sent'] - $x['back'];
            $k = $short === 'kept' ? $sh : 0; $m = $short === 'missing' ? $sh : 0;
            if ($x['back'] > 0) ReturnItem::create(['courier_return_id'=>$r->id, 'sales_order_item_id'=>$l->id, 'quantity'=>$x['back'],
                'damaged_quantity'=>$x['bad'], 'kept_quantity'=>$k, 'missing_quantity'=>$m]);
            $stock = $this->stock((int) $l->product_id); $ref = "RETURN:$r->id";
            if ($g) { $this->move($stock, 'transit_stock', -$g, 'return_qc', $ref, $actor); $this->move($stock, 'available_stock', $g, 'return_qc', $ref, $actor); }
            if ($x['bad']) { $this->move($stock, 'transit_stock', -$x['bad'], 'return_qc', $ref, $actor); $this->move($stock, 'damaged_stock', $x['bad'], 'return_qc', $ref, $actor); }
            if ($k) $this->move($stock, 'transit_stock', -$k, 'partial_delivered', $ref, $actor);
            if ($m) $this->move($stock, 'transit_stock', -$m, 'courier_short', $ref, $actor);
            $good += $g; $bad += $x['bad']; $kept += $k; $missing += $m;
        }
        $status = $kept ? 'partial_delivered' : ($missing ? 'returned_short' : ($good ? 'returned' : 'return_damaged'));
        $r->status = $good ? 'passed' : 'damaged'; $r->reviewed_by = $actor; $r->reviewed_at = now(); $r->save();
        $s->status = $status; $s->returned_at = now(); $s->returned_pieces = $good + $bad; $s->kept_pieces = $kept; $s->missing_pieces = $missing; $s->save();
        $o->status = $status; $o->save();
        $this->log($s, 'returned', $actor, ['good'=>$good, 'damaged'=>$bad, 'kept'=>$kept, 'missing'=>$missing]);
    }

    /** Packing station: every piece scanned into the parcel must match the order exactly. */
    public function pack(int $orderId, mixed $scans, int $actor): SalesOrder {
        $map = $this->scanMap($scans);
        if (!$map) $this->fail('Scan every piece before packing.');
        return $this->transaction(function () use ($orderId, $map, $actor) {
            $o = SalesOrder::whereKey($orderId)->lockForUpdate()->firstOrFail();
            if ($o->status === 'packed') return $o;
            abort_unless($o->status === 'confirmed', 409, $o->status === 'pending' ? 'Confirm the order before packing.' : "Order is already {$o->status}.");
            $this->assertScansMatch($o->items()->get()->mapWithKeys(fn ($i) => [(int) $i->product_id => (int) $i->quantity])->all(), $map);
            $o->status = 'packed'; $o->packed_at = now(); $o->packed_by = $actor; $o->save();
            return $o;
        });
    }

    /**
     * Mark parcels delivered from a list of tracking IDs (e.g. pasted from the courier's payment statement).
     * Not all-or-nothing: valid parcels are delivered, the rest are reported back.
     * @return array{delivered:int, already:int, problems:string[]}
     */
    public function markDeliveredBulk(array $data, int $actor): array {
        $d = Validator::make($data, ['courier'=>self::COURIER_RULE, 'tracking_ids'=>'required|array|min:1|max:5000', 'tracking_ids.*'=>'required|string|max:120'])->validate();
        $ids = array_values(array_unique(array_filter(array_map(fn ($t) => trim((string) $t), $d['tracking_ids']))));
        $ships = $this->shipmentsByTracking($d['courier'], $ids);
        $out = ['delivered'=>0, 'already'=>0, 'problems'=>[]];
        foreach ($ids as $t) {
            $s = $ships[$t] ?? null;
            if (!$s) { $out['problems'][] = "$t: not found for {$d['courier']}"; continue; }
            if ($s->status === 'delivered') { $out['already']++; continue; }
            if ($s->status === 'lost') $this->transaction(fn () => $this->unLose(Shipment::whereKey($s->id)->lockForUpdate()->first(), $actor));
            elseif ($s->status !== 'shipped') { $out['problems'][] = "$t (order #{$s->sales_order_id}): already {$s->status}"; continue; }
            $this->delivered((int) $s->sales_order_id, $actor);
            $out['delivered']++;
        }
        return $out;
    }

    /** Courier lost the parcel: its pieces leave transit for good (claim it from the courier). */
    public function markLost(int $shipmentId, int $actor): Shipment {
        return $this->transaction(function () use ($shipmentId, $actor) {
            $s = Shipment::whereKey($shipmentId)->lockForUpdate()->firstOrFail();
            if ($s->status === 'lost') return $s;
            abort_unless($s->status === 'shipped', 409, 'Only parcels still with the courier can be marked lost.');
            $o = $s->order()->lockForUpdate()->firstOrFail();
            foreach ($o->items()->orderBy('product_id')->get() as $i) {
                $this->move($this->stock((int) $i->product_id), 'transit_stock', -(int) $i->quantity, 'courier_lost', "SHIPMENT:$s->id", $actor);
            }
            $s->status = 'lost'; $s->lost_at = now(); $s->save();
            $o->status = 'lost'; $o->save();
            $this->log($s, 'lost', $actor);
            return $s;
        });
    }

    /** A "lost" parcel turned up: put its pieces back in transit and reopen it. Call inside transaction(). */
    private function unLose(Shipment $s, int $actor): void {
        $o = $s->order()->lockForUpdate()->firstOrFail();
        foreach ($o->items()->orderBy('product_id')->get() as $i) {
            $this->move($this->stock((int) $i->product_id), 'transit_stock', (int) $i->quantity, 'courier_found', "SHIPMENT:$s->id", $actor);
        }
        $s->status = 'shipped'; $s->lost_at = null; $s->save();
        $o->status = 'shipped'; $o->save();
        $this->log($s, 'found', $actor);
    }
}
