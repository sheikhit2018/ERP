<?php
namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\{Product, SalesOrder};
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SalesOrderController extends Controller {
    public const STATUSES = ['pending', 'confirmed', 'packed', 'shipped', 'delivered', 'partial_delivered', 'return_pending', 'returned', 'returned_short', 'return_damaged', 'lost', 'cancelled'];

    public function index(Request $r) {
        $status = $r->query('status');
        $q = trim((string) $r->query('q', ''));
        $orders = SalesOrder::with(['items:id,sales_order_id,quantity,unit_price_minor', 'shipment:id,sales_order_id,courier,tracking_id'])
            ->when(in_array($status, self::STATUSES, true), fn ($w) => $w->where('status', $status))
            ->when($q !== '', function ($w) use ($q) {
                $w->where(function ($x) use ($q) {
                    if (ctype_digit($q)) $x->orWhere('id', (int) $q);
                    $x->orWhereRaw(DB::getDriverName() === 'pgsql' ? 'customer::text ilike ?' : 'customer like ?', ['%'.$q.'%'])
                      ->orWhereHas('shipment', fn ($s) => $s->where('tracking_id', $q));
                });
            })
            ->latest('id')->paginate(25)->withQueryString();
        $risk = \App\Services\CustomerRisk::forPhones($orders->getCollection()->pluck('customer_phone')->filter()->all());
        return Inertia::render('Orders/Index', ['risk' => $risk, 'orders' => $orders, 'filters' => ['status' => $status, 'q' => $q], 'statuses' => self::STATUSES]);
    }
    public function create() {
        return Inertia::render('Orders/Create', [
            'products' => Product::with('stock:id,product_id,available_stock')->where('active', true)->orderBy('name')->orderBy('id')
                ->get(['id', 'sku', 'name', 'color', 'size', 'barcode', 'price_minor']),
        ]);
    }
    public function store(Request $r, InventoryService $s) {
        $data = $r->validate([
            'idempotency_key' => 'required|uuid', 'status' => 'required|in:pending,confirmed',
            'customer' => 'required|array', 'customer.name' => 'required|string|max:150',
            'customer.phone' => ['required', 'string', 'max:40', 'regex:/^[0-9+\-\s]{6,20}$/'],
            'customer.address' => 'required|string|max:1000',
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => 'required|integer|min:1|distinct',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price_minor' => 'required|integer|min:0',
            'delivery_charge_minor' => 'nullable|integer|min:0|max:100000000',
            'discount_minor' => 'nullable|integer|min:0|max:100000000',
            'source' => 'nullable|in:manual,website,facebook,phone,whatsapp,pos',
            'note' => 'nullable|string|max:1000',
        ]);
        $order = $s->place([
            'idempotency_key' => $data['idempotency_key'], 'status' => $data['status'], 'currency' => 'BDT',
            'customer' => ['name' => $data['customer']['name'], 'phone' => $data['customer']['phone'], 'address' => $data['customer']['address']],
            'items' => array_map(fn ($i) => ['product_id' => (int) $i['product_id'], 'quantity' => (int) $i['quantity'], 'unit_price_minor' => (int) $i['unit_price_minor']], $data['items']),
        ], (int) $r->user()->id);
        // Commercial extras live outside the stock state machine; set them only on first creation.
        if ($order->wasRecentlyCreated) {
            $order->forceFill([
                'delivery_charge_minor' => (int) ($data['delivery_charge_minor'] ?? 0), 'discount_minor' => (int) ($data['discount_minor'] ?? 0),
                'source' => $data['source'] ?? 'manual', 'note' => $data['note'] ?? null,
            ])->save();
        }
        return redirect()->route('orders.show', $order->id)->with('success', "Order #{$order->id} saved and stock reserved.");
    }
    public function show(int $order) {
        $o = SalesOrder::with(['items.product:id,sku,name,color,size,barcode', 'shipment.courierReturn', 'payments'])->findOrFail($order);
        $logs = $o->shipment ? \App\Models\CourierLog::where('shipment_id', $o->shipment->id)->latest('id')->get() : [];
        return Inertia::render('Orders/Show', ['order' => $o, 'logs' => $logs]);
    }
    public function invoice(int $order) {
        $o = SalesOrder::with(['items.product:id,sku,name,color,size', 'shipment:id,sales_order_id,courier,tracking_id', 'payments'])->findOrFail($order);
        return Inertia::render('Orders/Invoice', ['order' => $o]);
    }
    public function confirm(int $order, InventoryService $s) { $s->confirm($order); return back()->with('success', 'Order confirmed.'); }
    public function cancel(Request $r, int $order, InventoryService $s) { $s->cancel($order, (int) $r->user()->id); return back()->with('success', 'Order cancelled and stock released.'); }
    public function handover(Request $r, int $order, InventoryService $s) {
        // Warehouse must scan every unit before it leaves (barcode check against the order lines).
        // Packed orders were scanned at the packing station; otherwise every piece must be scanned here.
        $packed = SalesOrder::whereKey($order)->value('status') === 'packed';
        if (!$packed && !$r->filled('scans')) throw \Illuminate\Validation\ValidationException::withMessages(['scans' => 'Scan every item before handing over to the courier.']);
        $s->handover($order, $r->only('courier', 'tracking_id', 'scans'), (int) $r->user()->id);
        return back()->with('success', 'Handed over to courier. Stock moved to transit.');
    }
    public function delivered(Request $r, int $order, InventoryService $s) { $s->delivered($order, (int) $r->user()->id); return back()->with('success', 'Marked delivered.'); }
}
