<?php
namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\{CourierReturn, FinishedGoodStock, RawMaterialStock, SalesOrder};
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller {
    public function __invoke() {
        $today = now()->startOfDay();
        $statusCounts = SalesOrder::query()->select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status');

        // Order value = sum(qty * price) for orders created today (not cancelled).
        $todayValue = (int) DB::table('sales_order_items as i')
            ->join('sales_orders as o', function ($j) { $j->on('o.id', '=', 'i.sales_order_id')->on('o.tenant_id', '=', 'i.tenant_id'); })
            ->where('i.tenant_id', app(\App\Tenancy\TenantContext::class)->id())
            ->where('o.created_at', '>=', $today)->where('o.status', '!=', 'cancelled')
            ->sum(DB::raw('i.quantity * i.unit_price_minor'));

        $lowStock = FinishedGoodStock::with('product:id,sku,name,low_stock_level')
            ->whereHas('product', fn ($q) => $q->where('active', true))->get()
            ->filter(fn ($s) => $s->available_stock <= $s->product->low_stock_level)
            ->sortBy('available_stock')->take(8)->values()
            ->map(fn ($s) => ['id' => $s->product_id, 'sku' => $s->product->sku, 'name' => $s->product->name,
                'available' => (int) $s->available_stock, 'level' => (int) $s->product->low_stock_level]);

        $rawLow = RawMaterialStock::with('material:id,sku,name,base_unit,low_stock_level')->get()
            ->filter(fn ($s) => $s->material->low_stock_level > 0 && $s->quantity <= $s->material->low_stock_level)
            ->take(8)->values()->map(fn ($s) => ['id' => $s->raw_material_id, 'name' => $s->material->name,
                'unit' => $s->material->base_unit, 'quantity' => (int) $s->quantity]);

        $totals = FinishedGoodStock::query()->selectRaw('coalesce(sum(available_stock),0) a, coalesce(sum(reserved_stock),0) r, coalesce(sum(transit_stock),0) t, coalesce(sum(damaged_stock),0) d')->first();

        return Inertia::render('Dashboard', [
            'kpi' => [
                'orders_today' => SalesOrder::where('created_at', '>=', $today)->count(),
                'value_today_minor' => $todayValue,
                'pending' => (int) ($statusCounts['pending'] ?? 0),
                'confirmed' => (int) ($statusCounts['confirmed'] ?? 0),
                'shipped' => (int) ($statusCounts['shipped'] ?? 0),
                'returns_pending' => CourierReturn::where('status', 'pending')->count(),
            ],
            'stock' => ['available' => (int) $totals->a, 'reserved' => (int) $totals->r, 'transit' => (int) $totals->t, 'damaged' => (int) $totals->d],
            'lowStock' => $lowStock,
            'rawLow' => $rawLow,
            'recent' => SalesOrder::latest('id')->take(8)->get(['id', 'status', 'customer', 'created_at']),
        ]);
    }
}
