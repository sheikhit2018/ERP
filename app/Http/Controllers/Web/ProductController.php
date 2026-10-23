<?php
namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\{Product, ProductStyle};
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProductController extends Controller {
    public function index(Request $r) {
        $q = trim((string) $r->query('q', ''));
        $products = Product::with('stock')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%$q%")->orWhere('sku', 'like', "%$q%")
                ->orWhere('barcode', $q)->orWhere('color', 'like', "%$q%")))
            ->orderByRaw('case when style_id is null then 1 else 0 end')->orderBy('style_id')->orderBy('id')
            ->paginate(150)->withQueryString();
        $styleIds = $products->getCollection()->pluck('style_id')->filter()->unique();
        return Inertia::render('Products/Index', [
            'products' => $products, 'q' => $q,
            'styles' => ProductStyle::whereIn('id', $styleIds)->get(['id', 'code', 'name', 'barcode'])->keyBy('id'),
        ]);
    }
    /** With colours + sizes -> a style with variants. Without -> a single plain product. */
    public function store(Request $r, InventoryService $s) {
        if ($r->filled('colors') || $r->filled('sizes')) {
            $style = $s->createStyle($r->all(), (int) $r->user()->id);
            $n = Product::where('style_id', $style->id)->count();
            return back()->with('success', "{$style->name} created with $n variants and barcodes. Use Stock In to add quantities.");
        }
        $p = $s->createProduct($r->all(), (int) $r->user()->id);
        return back()->with('success', "Product {$p->name} created.");
    }
    public function addVariants(Request $r, int $style, InventoryService $s) {
        $n = $s->addVariants($style, $r->all());
        return back()->with('success', $n ? "$n new variants added." : 'Those colour/size combinations already exist.');
    }
    public function update(Request $r, int $product, InventoryService $s) {
        $s->updateProduct($product, $r->all());
        return back()->with('success', 'Product updated.');
    }
    public function barcode(Request $r, int $product, InventoryService $s) {
        $s->setBarcode($product, (string) $r->input('barcode'));
        return back()->with('success', 'Barcode updated.');
    }
    public function adjust(Request $r, int $product, InventoryService $s) {
        $s->adjustProduct($product, $r->all(), (int) $r->user()->id);
        return back()->with('success', 'Stock updated.');
    }
    /** Printable barcode labels for chosen variants (ids=1,2,3) or a whole style (style=ID). */
    public function labels(Request $r) {
        $ids = array_filter(array_map('intval', explode(',', (string) $r->query('ids', ''))));
        $items = Product::with('style:id,code')
            ->when($r->integer('style'), fn ($q, $sid) => $q->where('style_id', $sid))
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))
            ->when(!$ids && !$r->integer('style'), fn ($q) => $q->whereRaw('1 = 0'))
            ->orderBy('id')->get(['id', 'sku', 'name', 'color', 'size', 'barcode', 'price_minor', 'style_id']);
        return Inertia::render('Products/Labels', ['items' => $items]);
    }
}
