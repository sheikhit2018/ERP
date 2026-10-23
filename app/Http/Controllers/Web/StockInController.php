<?php
namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\{Product, ProductStyle, StockReceipt, User};
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StockInController extends Controller {
    public function index() {
        $receipts = StockReceipt::latest('id')->take(15)->get();
        return Inertia::render('Stock/In', [
            'receipts' => $receipts,
            'users' => User::whereIn('id', $receipts->pluck('created_by')->unique())->pluck('name', 'id'),
        ]);
    }
    public function store(Request $r, InventoryService $s) {
        $receipt = $s->receiveStock($r->all(), (int) $r->user()->id);
        return back()->with('success', "Stock In #{$receipt->id} saved: {$receipt->total_quantity} pcs across {$receipt->line_count} variants.");
    }
    /**
     * Barcode lookup for every scanner screen.
     * A style barcode returns the whole colour x size grid; a variant barcode returns that style with the variant marked.
     */
    public function lookup(Request $r) {
        $code = trim((string) $r->query('code', ''));
        abort_if($code === '' || strlen($code) > 64, 422, 'Scan a barcode.');
        $variant = Product::with('stock:id,product_id,available_stock')->where('barcode', $code)->first()
            ?? Product::with('stock:id,product_id,available_stock')->where('sku', $code)->first();
        $style = $variant?->style_id ? ProductStyle::find($variant->style_id) : ProductStyle::where('barcode', $code)->orWhere('code', strtoupper($code))->first();
        abort_unless($variant || $style, 404, "No product with barcode $code.");
        $variants = $style
            ? Product::with('stock:id,product_id,available_stock')->where('style_id', $style->id)->orderBy('id')->get()
            : collect([$variant]);
        return response()->json([
            'style' => $style?->only('id', 'code', 'name', 'barcode'),
            'matched_id' => $variant?->id,
            'variants' => $variants->map(fn ($p) => [
                'id' => $p->id, 'sku' => $p->sku, 'label' => $p->label, 'name' => $p->name, 'color' => $p->color, 'size' => $p->size,
                'barcode' => $p->barcode, 'price_minor' => (int) $p->price_minor, 'available' => (int) ($p->stock->available_stock ?? 0),
            ])->values(),
        ]);
    }
}
