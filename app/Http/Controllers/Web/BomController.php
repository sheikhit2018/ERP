<?php
namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\{Bom, Product, RawMaterial};
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BomController extends Controller {
    public function index() {
        return Inertia::render('Boms/Index', [
            'boms' => Bom::with(['product:id,sku,name', 'items.material:id,sku,name,base_unit'])->orderByDesc('active')->latest('id')->get(),
            'products' => Product::where('active', true)->orderBy('name')->get(['id', 'sku', 'name']),
            'materials' => RawMaterial::orderBy('name')->get(['id', 'sku', 'name', 'base_unit']),
        ]);
    }
    public function store(Request $r, InventoryService $s) {
        $bom = $s->createBom($r->all());
        return back()->with('success', "BOM version {$bom->version} saved.");
    }
}
