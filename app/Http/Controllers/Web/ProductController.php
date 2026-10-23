<?php
namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProductController extends Controller {
    public function index(Request $r) {
        $q = trim((string) $r->query('q', ''));
        $products = Product::with('stock')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%$q%")->orWhere('sku', 'like', "%$q%")))
            ->orderBy('name')->paginate(25)->withQueryString();
        return Inertia::render('Products/Index', ['products' => $products, 'q' => $q]);
    }
    public function store(Request $r, InventoryService $s) {
        $p = $s->createProduct($r->all(), (int) $r->user()->id);
        return back()->with('success', "Product {$p->name} created.");
    }
    public function update(Request $r, int $product, InventoryService $s) {
        $s->updateProduct($product, $r->all());
        return back()->with('success', 'Product updated.');
    }
    public function adjust(Request $r, int $product, InventoryService $s) {
        $s->adjustProduct($product, $r->all(), (int) $r->user()->id);
        return back()->with('success', 'Stock updated.');
    }
}
