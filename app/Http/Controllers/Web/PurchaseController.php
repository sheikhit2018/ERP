<?php
namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\{LedgerEntry, MoneyAccount, Product, PurchaseOrder, RawMaterial, Supplier};
use App\Services\{Accounts, PurchaseService};
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Suppliers & purchases: order, receive goods (stock in), pay, balance due. */
class PurchaseController extends Controller {
    public function suppliers() {
        $dues = PurchaseService::dues();
        return Inertia::render('Purchases/Suppliers', ['suppliers' => Supplier::orderBy('name')->get()->map(fn ($s) => $s->only('id', 'name', 'phone', 'address', 'note', 'opening_due_minor', 'active') + ($dues[$s->id] ?? ['received' => 0, 'paid' => 0, 'due' => 0]))]);
    }
    public function saveSupplier(Request $r, PurchaseService $s) { $s->saveSupplier($r->all()); return back()->with('success', 'Supplier saved.'); }
    public function supplier(int $supplier) {
        $s = Supplier::findOrFail($supplier);
        Accounts::defaults();
        return Inertia::render('Purchases/Supplier', [
            'supplier' => $s, 'due' => PurchaseService::dues([$s->id])[$s->id],
            'purchases' => PurchaseOrder::where('supplier_id', $s->id)->latest('id')->with('items:id,purchase_order_id,quantity,unit_cost_minor,received_quantity')->take(100)->get()
                ->map(fn ($p) => $p->only('id', 'status', 'order_date', 'note') + ['total' => (int) $p->items->sum(fn ($i) => $i->quantity * $i->unit_cost_minor),
                    'received' => (int) $p->items->sum(fn ($i) => $i->received_quantity * $i->unit_cost_minor)]),
            'payments' => LedgerEntry::with('account:id,name')->where('party_type', 'supplier')->where('party_id', $s->id)->latest('entry_date')->take(100)->get(),
            'accounts' => MoneyAccount::where('active', true)->get(['id', 'name']),
        ]);
    }
    public function pay(Request $r, int $supplier, PurchaseService $s) { $s->pay($supplier, $r->all(), (int) $r->user()->id); return back()->with('success', 'Payment saved to the cash book.'); }

    public function index(Request $r) {
        $status = in_array($r->query('status'), ['ordered', 'partial', 'received', 'cancelled'], true) ? $r->query('status') : null;
        $pos = PurchaseOrder::with(['supplier:id,name', 'items:id,purchase_order_id,quantity,unit_cost_minor,received_quantity'])->when($status, fn ($q) => $q->where('status', $status))
            ->latest('id')->paginate(50)->withQueryString();
        $pos->setCollection($pos->getCollection()->map(fn ($p) => $p->only('id', 'status', 'order_date', 'expected_date', 'note') + ['supplier' => $p->supplier?->name,
            'total' => (int) $p->items->sum(fn ($i) => $i->quantity * $i->unit_cost_minor), 'received' => (int) $p->items->sum(fn ($i) => $i->received_quantity * $i->unit_cost_minor)]));
        return Inertia::render('Purchases/Index', ['purchases' => $pos, 'status' => $status]);
    }
    public function create() {
        return Inertia::render('Purchases/Create', [
            'suppliers' => Supplier::where('active', true)->orderBy('name')->get(['id', 'name']),
            'products' => Product::orderBy('name')->orderBy('id')->get(['id', 'sku', 'name', 'color', 'size', 'barcode', 'cost_minor']),
            'materials' => RawMaterial::orderBy('name')->get(['id', 'sku', 'name', 'base_unit']),
        ]);
    }
    public function store(Request $r, PurchaseService $s) {
        $po = $s->createOrder($r->all(), (int) $r->user()->id);
        return redirect()->route('purchases.show', $po->id)->with('success', "Purchase #{$po->id} created.");
    }
    public function show(int $purchase) {
        $po = PurchaseOrder::with(['supplier', 'items.product:id,sku,name,color,size,barcode', 'items.material:id,sku,name,base_unit', 'receipts'])->findOrFail($purchase);
        return Inertia::render('Purchases/Show', ['po' => $po]);
    }
    public function receive(Request $r, int $purchase, PurchaseService $s) {
        $rc = $s->receive($purchase, $r->all(), (int) $r->user()->id);
        return back()->with('success', 'Goods received: ৳'.number_format($rc->value_minor / 100, 2).' added to stock.');
    }
    public function cancel(int $purchase, PurchaseService $s) { $s->cancel($purchase); return back()->with('success', 'Purchase cancelled.'); }
}
