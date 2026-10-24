<?php
namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\{Location, LocationStock, PosSale, Product, StockCount, StockCountLine, StockTransfer, User};
use App\Services\StoreService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Locations & transfers, point of sale, physical stock counts. */
class StoreController extends Controller {
    private function locations() { StoreService::main(); return Location::orderByDesc('is_main')->orderBy('name')->get(['id', 'name', 'type', 'is_main', 'address', 'active']); }
    private function loc(Request $r, string $key = 'location'): Location {
        $id = (int) $r->query($key, 0);
        return ($id ? Location::where('active', true)->find($id) : null) ?? StoreService::main();
    }
    /** Product list for client-side barcode / SKU matching, with quantity at one location. */
    private function catalog(Location $loc): array {
        $q = StoreService::quantities($loc);
        return Product::orderBy('name')->orderBy('id')->get(['id', 'sku', 'name', 'color', 'size', 'barcode', 'price_minor', 'cost_minor'])
            ->map(fn ($p) => $p->only('id', 'sku', 'name', 'color', 'size', 'barcode', 'price_minor') + ['qty' => (int) ($q[$p->id] ?? 0)])->all();
    }

    public function index(Request $r) {
        $locs = $this->locations();
        $loc = $this->loc($r);
        $value = Product::pluck('cost_minor', 'id');
        $summary = $locs->map(function ($l) use ($value) { $q = StoreService::quantities($l);
            return $l->only('id', 'name', 'type', 'is_main', 'address', 'active') + ['pieces' => array_sum($q), 'value' => (int) collect($q)->reduce(fn ($s, $n, $pid) => $s + $n * (int) ($value[$pid] ?? 0), 0)]; });
        return Inertia::render('Store/Locations', ['locations' => $summary, 'current' => $loc->id,
            'stock' => collect($this->catalog($loc))->filter(fn ($p) => $p['qty'] > 0)->values(),
            'transfers' => StockTransfer::with(['from:id,name', 'to:id,name'])->latest('id')->take(30)->get()->map(fn ($t) => ['id' => $t->id, 'from' => $t->from?->name, 'to' => $t->to?->name,
                'pieces' => $t->piece_count, 'note' => $t->note, 'created_at' => $t->created_at])]);
    }
    public function saveLocation(Request $r, StoreService $s) { $s->saveLocation($r->all()); return back()->with('success', 'Location saved.'); }
    public function transferForm(Request $r) {
        $from = $this->loc($r, 'from');
        return Inertia::render('Store/Transfer', ['locations' => $this->locations()->where('active', true)->values(), 'from' => $from->id, 'products' => $this->catalog($from)]);
    }
    public function transfer(Request $r, StoreService $s) {
        $t = $s->transfer($r->all(), (int) $r->user()->id);
        return redirect('/locations?location='.$t->to_location_id)->with('success', "Transfer #$t->id: {$t->piece_count} pcs moved.");
    }

    public function pos(Request $r) {
        $loc = $this->loc($r);
        $today = PosSale::where('location_id', $loc->id)->where('created_at', '>=', now()->startOfDay());
        return Inertia::render('Store/Pos', ['locations' => $this->locations()->where('active', true)->values(), 'location' => $loc->only('id', 'name', 'is_main'), 'products' => $this->catalog($loc),
            'today' => ['count' => (clone $today)->where('status', 'paid')->count(), 'total' => (int) (clone $today)->where('status', 'paid')->sum('total_minor'),
                'by_method' => (clone $today)->where('status', 'paid')->groupBy('method')->selectRaw('method, sum(total_minor) as t')->pluck('t', 'method')],
            'recent' => (clone $today)->latest('id')->take(15)->get(['id', 'total_minor', 'method', 'status', 'customer_name', 'created_at'])]);
    }
    public function sell(Request $r, StoreService $s) {
        $sale = $s->sell($r->all(), (int) $r->user()->id);
        return back()->with('success', "Sale #$sale->id saved.")->with('pos_sale', $sale->id);
    }
    public function receipt(int $sale) {
        $s = PosSale::with(['items.product:id,sku,name,color,size', 'location:id,name,address'])->findOrFail($sale);
        return Inertia::render('Store/Receipt', ['sale' => $s, 'cashier' => User::whereKey($s->created_by)->value('name')]);
    }
    public function void(int $sale, StoreService $s, Request $r) { $s->void($sale, (int) $r->user()->id); return back()->with('success', "Sale #$sale voided — stock returned and cash entry reversed."); }

    public function counts() {
        return Inertia::render('Store/Counts', ['locations' => $this->locations()->where('active', true)->values(),
            'counts' => StockCount::with('location:id,name')->latest('id')->take(50)->get()->map(fn ($c) => ['id' => $c->id, 'location' => $c->location?->name, 'status' => $c->status, 'note' => $c->note,
                'created_at' => $c->created_at, 'approved_at' => $c->approved_at, 'lines' => StockCountLine::where('stock_count_id', $c->id)->count()])]);
    }
    public function startCount(Request $r, StoreService $s) {
        $c = $s->startCount((int) $r->input('location_id'), $r->input('note'), (int) $r->user()->id);
        return redirect("/stock-counts/$c->id");
    }
    public function count(int $count) {
        $c = StockCount::with('location')->findOrFail($count);
        $lines = StockCountLine::where('stock_count_id', $c->id)->pluck('counted_qty', 'product_id');
        $system = $c->status === 'open' ? StoreService::quantities($c->location) : StockCountLine::where('stock_count_id', $c->id)->pluck('system_qty', 'product_id')->all();
        return Inertia::render('Store/Count', ['count' => $c->only('id', 'status', 'note', 'created_at', 'approved_at') + ['location' => $c->location->name],
            'products' => Product::orderBy('name')->orderBy('id')->get(['id', 'sku', 'name', 'color', 'size', 'barcode', 'cost_minor'])
                ->map(fn ($p) => $p->only('id', 'sku', 'name', 'color', 'size', 'barcode', 'cost_minor') + ['system' => (int) ($system[$p->id] ?? 0), 'counted' => isset($lines[$p->id]) ? (int) $lines[$p->id] : null])]);
    }
    public function recordCount(Request $r, int $count, StoreService $s) {
        $s->recordCount($count, (array) $r->input('counts', []), $r->input('mode') === 'add' ? 'add' : 'set');
        return back();
    }
    public function approveCount(Request $r, int $count, StoreService $s) { $s->approveCount($count, $r->boolean('zero_missing'), (int) $r->user()->id); return back()->with('success', 'Count approved — stock adjusted to what was counted.'); }
    public function cancelCount(int $count) {
        $c = StockCount::findOrFail($count);
        if ($c->status === 'open') $c->forceFill(['status' => 'cancelled'])->save();
        return redirect('/stock-counts')->with('success', 'Count cancelled. Stock unchanged.');
    }
}
