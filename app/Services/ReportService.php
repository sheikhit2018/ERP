<?php
namespace App\Services;

use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Read-only business reports for the current company. Money in paisa. */
class ReportService {
    private int $t;
    public function __construct(private Carbon $from, private Carbon $to) { $this->t = app(TenantContext::class)->id(); }

    private function q(string $table) { return DB::table($table)->where("$table.tenant_id", $this->t); }
    private function itemsValue() {
        return DB::table('sales_order_items')->where('tenant_id', $this->t)->groupBy('sales_order_id')
            ->selectRaw('sales_order_id, sum(quantity * unit_price_minor) as value, sum(quantity * unit_cost_minor) as cost, sum(quantity) as pieces');
    }

    /** Orders booked per day (by order date), excluding cancelled. */
    public function sales(): array {
        $rows = $this->q('sales_orders')->joinSub($this->itemsValue(), 'v', 'v.sales_order_id', '=', 'sales_orders.id')
            ->whereBetween('sales_orders.created_at', [$this->from, $this->to])->where('sales_orders.status', '!=', 'cancelled')
            ->selectRaw('date(sales_orders.created_at) as day, count(*) as orders, sum(v.pieces) as pieces,
                sum(v.value + sales_orders.delivery_charge_minor - sales_orders.discount_minor) as value')
            ->groupBy('day')->orderBy('day')->get()->map(fn ($r) => ['day' => (string) $r->day, 'orders' => (int) $r->orders, 'pieces' => (int) $r->pieces, 'value' => (int) $r->value]);
        $bySource = $this->q('sales_orders')->joinSub($this->itemsValue(), 'v', 'v.sales_order_id', '=', 'sales_orders.id')
            ->whereBetween('sales_orders.created_at', [$this->from, $this->to])->where('sales_orders.status', '!=', 'cancelled')
            ->selectRaw('sales_orders.source, count(*) as orders, sum(v.value + sales_orders.delivery_charge_minor - sales_orders.discount_minor) as value')
            ->groupBy('sales_orders.source')->orderByDesc('value')->get()->map(fn ($r) => ['source' => $r->source, 'orders' => (int) $r->orders, 'value' => (int) $r->value]);
        $byStatus = $this->q('sales_orders')->whereBetween('created_at', [$this->from, $this->to])->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);
        return ['days' => $rows->values(), 'bySource' => $bySource->values(), 'byStatus' => $byStatus,
            'totals' => ['orders' => $rows->sum('orders'), 'pieces' => $rows->sum('pieces'), 'value' => $rows->sum('value'), 'cancelled' => (int) ($byStatus['cancelled'] ?? 0)]];
    }

    /**
     * Profit on parcels closed in the period (delivered, or partly delivered on return).
     * Revenue/cost of a partly delivered parcel = kept pieces' share of the order.
     */
    public function profit(): array {
        $s = $this->q('shipments')->join('sales_orders as o', fn ($j) => $j->on('o.id', '=', 'shipments.sales_order_id')->on('o.tenant_id', '=', 'shipments.tenant_id'))
            ->joinSub($this->itemsValue(), 'v', 'v.sales_order_id', '=', 'shipments.sales_order_id');
        $delivered = (clone $s)->whereBetween('shipments.delivered_at', [$this->from, $this->to])
            ->selectRaw('count(*) as n, coalesce(sum(v.value),0) as revenue, coalesce(sum(v.cost),0) as cost, coalesce(sum(o.delivery_charge_minor),0) as delivery, coalesce(sum(o.discount_minor),0) as discount')->first();
        $partial = (clone $s)->whereBetween('shipments.returned_at', [$this->from, $this->to])->where('shipments.kept_pieces', '>', 0)
            ->get(['v.value', 'v.cost', 'v.pieces', 'shipments.kept_pieces', 'shipments.cod_collected_minor']);
        $pRevenue = (int) $partial->sum(fn ($r) => $r->pieces ? intdiv((int) $r->value * (int) $r->kept_pieces, (int) $r->pieces) : 0);
        $pCost = (int) $partial->sum(fn ($r) => $r->pieces ? intdiv((int) $r->cost * (int) $r->kept_pieces, (int) $r->pieces) : 0);
        $charges = (int) $this->q('shipments')->where(fn ($w) => $w->whereBetween('delivered_at', [$this->from, $this->to])->orWhereBetween('returned_at', [$this->from, $this->to]))
            ->sum('courier_charge_minor');
        // Stock lost to returns: damaged + missing pieces, and parcels lost by the courier, valued at cost.
        $lossQ = fn (string $reason) => (int) $this->q('inventory_movements')->join('products as p', 'p.id', '=', 'inventory_movements.product_id')
            ->whereBetween('inventory_movements.created_at', [$this->from, $this->to])->where('inventory_movements.reason', $reason)
            ->where('inventory_movements.delta', '<', 0)->sum(DB::raw('-inventory_movements.delta * p.cost_minor'));
        $damaged = (int) $this->q('inventory_movements')->join('products as p', 'p.id', '=', 'inventory_movements.product_id')
            ->whereBetween('inventory_movements.created_at', [$this->from, $this->to])->where('inventory_movements.bucket', 'damaged_stock')->where('inventory_movements.delta', '>', 0)
            ->sum(DB::raw('inventory_movements.delta * p.cost_minor'));
        $lost = $lossQ('courier_lost') + $lossQ('courier_short');
        $revenue = (int) $delivered->revenue + $pRevenue; $cogs = (int) $delivered->cost + $pCost;
        $gross = $revenue + (int) $delivered->delivery - (int) $delivered->discount - $cogs - $charges;
        return ['delivered_orders' => (int) $delivered->n, 'partial_orders' => $partial->count(), 'revenue' => $revenue, 'delivery_income' => (int) $delivered->delivery,
            'discount' => (int) $delivered->discount, 'cogs' => $cogs, 'courier_charges' => $charges, 'gross_profit' => $gross,
            'damaged_cost' => $damaged, 'lost_cost' => $lost, 'net_after_losses' => $gross - $damaged - $lost,
            'margin' => $revenue ? round(100 * $gross / $revenue, 1) : null];
    }

    /** Per variant: sold, returned, return rate, revenue, profit (orders placed in the period). */
    public function products(): array {
        $sold = $this->q('sales_order_items')->join('sales_orders as o', fn ($j) => $j->on('o.id', '=', 'sales_order_items.sales_order_id')->on('o.tenant_id', '=', 'sales_order_items.tenant_id'))
            ->join('products as p', 'p.id', '=', 'sales_order_items.product_id')
            ->whereBetween('o.created_at', [$this->from, $this->to])->where('o.status', '!=', 'cancelled')
            ->groupBy('p.id', 'p.name', 'p.color', 'p.size', 'p.sku')
            ->selectRaw('p.id, p.name, p.color, p.size, p.sku, sum(sales_order_items.quantity) as qty, sum(sales_order_items.quantity * sales_order_items.unit_price_minor) as revenue,
                sum(sales_order_items.quantity * sales_order_items.unit_cost_minor) as cost')->get()->keyBy('id');
        $ret = $this->q('return_items')->join('sales_order_items as i', 'i.id', '=', 'return_items.sales_order_item_id')
            ->join('sales_orders as o', 'o.id', '=', 'i.sales_order_id')->whereBetween('o.created_at', [$this->from, $this->to])
            ->groupBy('i.product_id')->selectRaw('i.product_id, sum(return_items.quantity) as back')->pluck('back', 'product_id');
        $rows = $sold->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'color' => $r->color, 'size' => $r->size, 'sku' => $r->sku,
            'qty' => (int) $r->qty, 'returned' => (int) ($ret[$r->id] ?? 0), 'revenue' => (int) $r->revenue, 'profit' => (int) $r->revenue - (int) $r->cost,
            'return_rate' => $r->qty ? round(100 * (int) ($ret[$r->id] ?? 0) / (int) $r->qty, 1) : 0])->sortByDesc('qty')->values();
        $sizes = $rows->groupBy(fn ($r) => $r['size'] ?? '—')->map(fn ($g, $k) => ['size' => $k, 'qty' => $g->sum('qty'), 'returned' => $g->sum('returned')])->sortByDesc('qty')->values();
        $colors = $rows->groupBy(fn ($r) => $r['color'] ?? '—')->map(fn ($g, $k) => ['color' => $k, 'qty' => $g->sum('qty'), 'returned' => $g->sum('returned')])->sortByDesc('qty')->values();
        return ['rows' => $rows, 'sizes' => $sizes, 'colors' => $colors];
    }

    /** Per courier: parcels, delivery success, average days to deliver, charges (handed over in the period). */
    public function couriers(): array {
        $days = DB::getDriverName() === 'pgsql' ? 'extract(epoch from (delivered_at - handed_over_at)) / 86400.0' : 'timestampdiff(second, handed_over_at, delivered_at) / 86400.0';
        return $this->q('shipments')->whereBetween('handed_over_at', [$this->from, $this->to])->groupBy('courier')
            ->selectRaw("courier, count(*) as sent, sum(piece_count) as pieces,
                sum(case when delivered_at is not null or kept_pieces > 0 then 1 else 0 end) as delivered,
                sum(case when returned_at is not null and kept_pieces = 0 then 1 else 0 end) as returned,
                sum(case when lost_at is not null or missing_pieces > 0 then 1 else 0 end) as lost,
                sum(case when delivered_at is null and returned_at is null and lost_at is null then 1 else 0 end) as open,
                avg(case when delivered_at is not null then $days end) as avg_days,
                coalesce(sum(courier_charge_minor),0) as charges")->get()
            ->map(function ($r) {
                $closed = (int) $r->delivered + (int) $r->returned;
                return ['courier' => $r->courier, 'sent' => (int) $r->sent, 'pieces' => (int) $r->pieces, 'delivered' => (int) $r->delivered, 'returned' => (int) $r->returned,
                    'lost' => (int) $r->lost, 'open' => (int) $r->open, 'success' => $closed ? round(100 * (int) $r->delivered / $closed, 1) : null,
                    'avg_days' => $r->avg_days !== null ? round((float) $r->avg_days, 1) : null, 'charges' => (int) $r->charges];
            })->values()->all();
    }

    /** Stock value at cost and at sale price, and dead stock (nothing sold for $deadDays days). */
    public function stock(int $deadDays = 60): array {
        $last = $this->q('sales_order_items')->join('sales_orders as o', 'o.id', '=', 'sales_order_items.sales_order_id')
            ->where('o.status', '!=', 'cancelled')->groupBy('sales_order_items.product_id')
            ->selectRaw('sales_order_items.product_id, max(o.created_at) as last_sold')->pluck('last_sold', 'product_id');
        $rows = $this->q('finished_good_stocks')->join('products as p', 'p.id', '=', 'finished_good_stocks.product_id')
            ->get(['p.id', 'p.name', 'p.color', 'p.size', 'p.sku', 'p.cost_minor', 'p.price_minor', 'finished_good_stocks.available_stock',
                'finished_good_stocks.reserved_stock', 'finished_good_stocks.transit_stock', 'finished_good_stocks.damaged_stock'])
            ->map(function ($r) use ($last, $deadDays) {
                $own = (int) $r->available_stock + (int) $r->reserved_stock;
                $ls = $last[$r->id] ?? null;
                return ['id' => (int) $r->id, 'name' => $r->name, 'color' => $r->color, 'size' => $r->size, 'sku' => $r->sku,
                    'available' => (int) $r->available_stock, 'reserved' => (int) $r->reserved_stock, 'transit' => (int) $r->transit_stock, 'damaged' => (int) $r->damaged_stock,
                    'cost_value' => $own * (int) $r->cost_minor, 'sale_value' => $own * (int) $r->price_minor, 'last_sold' => $ls,
                    'dead' => (int) $r->available_stock > 0 && (!$ls || Carbon::parse($ls)->lt(now()->subDays($deadDays)))];
            })->sortByDesc('cost_value')->values();
        return ['rows' => $rows, 'totals' => ['pieces' => $rows->sum(fn ($r) => $r['available'] + $r['reserved']), 'cost_value' => $rows->sum('cost_value'),
            'sale_value' => $rows->sum('sale_value'), 'transit' => $rows->sum('transit'), 'damaged' => $rows->sum('damaged'),
            'dead_items' => $rows->where('dead', true)->count(), 'dead_cost' => $rows->where('dead', true)->sum('cost_value')], 'dead_days' => $deadDays];
    }
}
