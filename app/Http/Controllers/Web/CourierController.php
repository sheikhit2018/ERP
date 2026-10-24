<?php
namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\{CourierBatch, SalesOrder, Shipment, User};
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Courier hub: bulk handover, bulk returns, delivered list, lost parcels and reconciliation.
 * Sent parcels − delivered − returned − lost = still with the courier.
 * Still with the courier after the return window (default 3 days) = overdue → ask the courier / mark lost.
 */
class CourierController extends Controller {
    public const COURIERS = ['steadfast' => 'Steadfast', 'pathao' => 'Pathao', 'redx' => 'RedX', 'paperfly' => 'Paperfly',
        'ecourier' => 'eCourier', 'sundarban' => 'Sundarban', 'self' => 'Own delivery'];

    private function window(Request $r): int { return max(1, min(60, (int) ($r->query('days') ?: 3))); }

    /** Aggregate parcel counters over a shipments query. */
    private function counters($q, Carbon $overdueBefore) {
        $open = 'delivered_at is null and returned_at is null and lost_at is null';
        return $q->selectRaw("count(*) as sent_parcels, coalesce(sum(piece_count),0) as sent_pieces,
            sum(case when delivered_at is not null then 1 else 0 end) as delivered_parcels,
            coalesce(sum(case when delivered_at is not null then piece_count else 0 end),0) as delivered_pieces,
            sum(case when returned_at is not null then 1 else 0 end) as returned_parcels,
            coalesce(sum(case when returned_at is not null then piece_count else 0 end),0) as returned_pieces,
            sum(case when lost_at is not null then 1 else 0 end) as lost_parcels,
            coalesce(sum(case when lost_at is not null then piece_count else 0 end),0) as lost_pieces,
            sum(case when $open then 1 else 0 end) as open_parcels,
            coalesce(sum(case when $open then piece_count else 0 end),0) as open_pieces,
            sum(case when $open and handed_over_at < ? then 1 else 0 end) as overdue_parcels,
            coalesce(sum(case when $open and handed_over_at < ? then piece_count else 0 end),0) as overdue_pieces",
            [$overdueBefore, $overdueBefore]);
    }
    private static function ints($row): array {
        return collect((array) ($row instanceof \Illuminate\Database\Eloquent\Model ? $row->getAttributes() : $row))
            ->map(fn ($v, $k) => in_array($k, ['courier', 'dispatch_batch_id', 'day'], true) ? $v : (int) $v)->all();
    }

    public function index(Request $r) {
        $days = $this->window($r);
        $from = Carbon::parse($r->query('from') ?: now()->subDays(29)->toDateString())->startOfDay();
        $to = Carbon::parse($r->query('to') ?: now()->toDateString())->endOfDay();
        $courier = array_key_exists((string) $r->query('courier'), self::COURIERS) ? $r->query('courier') : null;
        $overdueBefore = now()->subDays($days);
        $base = fn () => Shipment::query()->whereBetween('handed_over_at', [$from, $to])->when($courier, fn ($q) => $q->where('courier', $courier));

        $total = self::ints($this->counters($base(), $overdueBefore)->first());
        $byCourier = $this->counters($base(), $overdueBefore)->addSelect('courier')->groupBy('courier')->get()->map(fn ($x) => self::ints($x));
        $byBatch = $this->counters($base(), $overdueBefore)->addSelect('dispatch_batch_id')->whereNotNull('dispatch_batch_id')
            ->groupBy('dispatch_batch_id')->get()->map(fn ($x) => self::ints($x))->keyBy('dispatch_batch_id');
        $batches = CourierBatch::where('type', 'dispatch')->whereIn('id', $byBatch->keys())->latest('id')->get()
            ->map(fn ($b) => $b->only('id', 'courier', 'parcel_count', 'piece_count', 'note', 'created_at', 'created_by') + ['stats' => $byBatch[$b->id] ?? null]);
        $returns = CourierBatch::where('type', 'return')->whereBetween('created_at', [$from, $to])->when($courier, fn ($q) => $q->where('courier', $courier))
            ->latest('id')->take(30)->get(['id', 'courier', 'parcel_count', 'piece_count', 'note', 'created_at', 'created_by']);
        $overdue = $base()->with('order:id,customer')->whereNull('delivered_at')->whereNull('returned_at')->whereNull('lost_at')
            ->where('handed_over_at', '<', $overdueBefore)->orderBy('handed_over_at')->take(300)
            ->get(['id', 'sales_order_id', 'courier', 'tracking_id', 'piece_count', 'handed_over_at', 'dispatch_batch_id']);

        return Inertia::render('Courier/Index', [
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'courier' => $courier, 'days' => $days],
            'couriers' => self::COURIERS, 'total' => $total, 'byCourier' => $byCourier, 'batches' => $batches, 'returns' => $returns,
            'overdue' => $overdue, 'users' => User::whereIn('id', $batches->pluck('created_by')->merge($returns->pluck('created_by'))->unique())->pluck('name', 'id'),
        ]);
    }

    public function dispatchPage() {
        $orders = SalesOrder::with(['items:id,sales_order_id,quantity'])->where('status', 'confirmed')->doesntHave('shipment')
            ->oldest('id')->take(1000)->get(['id', 'customer', 'created_at']);
        return Inertia::render('Courier/Dispatch', [
            'couriers' => self::COURIERS,
            'orders' => $orders->map(fn ($o) => ['id' => $o->id, 'name' => $o->customer['name'] ?? '', 'phone' => $o->customer['phone'] ?? '',
                'address' => $o->customer['address'] ?? '', 'pieces' => (int) $o->items->sum('quantity'), 'created_at' => $o->created_at])->values(),
        ]);
    }
    public function dispatchStore(Request $r, InventoryService $s) {
        $b = $s->dispatchBatch($r->all(), (int) $r->user()->id);
        return redirect()->route('courier.batch', $b->id)->with('success', "Handover #{$b->id} saved: {$b->parcel_count} parcels, {$b->piece_count} pcs to ".(self::COURIERS[$b->courier] ?? $b->courier).'. Print the manifest for the courier to sign.');
    }

    public function returnsPage() {
        return Inertia::render('Courier/Returns', ['couriers' => self::COURIERS]);
    }
    /** JSON lookup for the return scanner: what is in this parcel, and can it come back? */
    public function parcel(Request $r) {
        $t = trim((string) $r->query('tracking', ''));
        abort_if($t === '' || strlen($t) > 120, 422, 'Scan a tracking ID.');
        $s = Shipment::with(['order.items.product:id,name,color,size,sku'])->where('tracking_id', $t)
            ->when(array_key_exists((string) $r->query('courier'), self::COURIERS), fn ($q) => $q->where('courier', $r->query('courier')))->first();
        abort_unless($s, 404, "Tracking $t not found.");
        return response()->json([
            'shipment_id' => $s->id, 'order_id' => $s->sales_order_id, 'courier' => $s->courier, 'tracking_id' => $s->tracking_id, 'status' => $s->status,
            'pieces' => (int) $s->piece_count, 'customer' => $s->order->customer['name'] ?? '', 'handed_over_at' => $s->handed_over_at,
            'items' => $s->order->items->map(fn ($i) => ['label' => $i->product->label, 'quantity' => (int) $i->quantity])->values(),
        ]);
    }
    public function returnsStore(Request $r, InventoryService $s) {
        $b = $s->returnBatch($r->all(), (int) $r->user()->id);
        return redirect()->route('courier.index')->with('success', "Return #{$b->id} saved: {$b->parcel_count} parcels, {$b->piece_count} pcs back in stock.");
    }

    public function deliveredPage() {
        return Inertia::render('Courier/Delivered', ['couriers' => self::COURIERS]);
    }
    public function deliveredStore(Request $r, InventoryService $s) {
        $res = $s->markDeliveredBulk($r->all(), (int) $r->user()->id);
        $msg = "{$res['delivered']} parcels marked delivered".($res['already'] ? ", {$res['already']} were already delivered" : '').'.';
        return back()->with('success', $msg)->with('report', $res['problems']);
    }

    public function batch(Request $r, int $batch) {
        $b = CourierBatch::where('type', 'dispatch')->findOrFail($batch);
        $days = $this->window($r);
        $parcels = Shipment::with(['order:id,customer', 'order.items:id,sales_order_id,product_id,quantity', 'order.items.product:id,name,color,size'])
            ->where('dispatch_batch_id', $b->id)->orderBy('id')->get();
        return Inertia::render('Courier/Batch', [
            'batch' => $b->only('id', 'courier', 'parcel_count', 'piece_count', 'note', 'created_at') + ['by' => User::find($b->created_by)?->name],
            'courierName' => self::COURIERS[$b->courier] ?? $b->courier, 'days' => $days,
            'stats' => self::ints($this->counters(Shipment::where('dispatch_batch_id', $b->id), now()->subDays($days))->first()),
            'parcels' => $parcels->map(fn ($s) => [
                'id' => $s->id, 'order_id' => $s->sales_order_id, 'tracking_id' => $s->tracking_id, 'status' => $s->status, 'pieces' => (int) $s->piece_count,
                'customer' => $s->order->customer['name'] ?? '', 'phone' => $s->order->customer['phone'] ?? '', 'address' => $s->order->customer['address'] ?? '',
                'items' => $s->order->items->map(fn ($i) => $i->quantity.' × '.$i->product->label)->implode(', '),
                'handed_over_at' => $s->handed_over_at, 'delivered_at' => $s->delivered_at, 'returned_at' => $s->returned_at, 'lost_at' => $s->lost_at,
            ]),
        ]);
    }
    public function lost(Request $r, int $shipment, InventoryService $s) {
        $s->markLost($shipment, (int) $r->user()->id);
        return back()->with('success', 'Parcel marked lost. Its pieces were removed from transit stock — claim them from the courier.');
    }
    public function deliveredOne(Request $r, int $shipment, InventoryService $s) {
        $sh = Shipment::findOrFail($shipment);
        $s->markDeliveredBulk(['courier' => $sh->courier, 'tracking_ids' => [$sh->tracking_id]], (int) $r->user()->id);
        return back()->with('success', 'Parcel marked delivered.');
    }
}
