<?php
namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\{Bom, ManufacturingOrder};
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ManufacturingPageController extends Controller {
    public function index() {
        return Inertia::render('Manufacturing/Index', [
            'boms' => Bom::with(['product:id,sku,name', 'items.material:id,name,base_unit', 'items.material.stock'])->where('active', true)->get(),
            'orders' => ManufacturingOrder::with('bom.product:id,sku,name')->latest('id')->take(50)->get(),
        ]);
    }
    public function store(Request $r, InventoryService $s) {
        $mo = $s->manufacture($r->all(), (int) $r->user()->id);
        return back()->with('success', "Production #{$mo->id} completed: {$mo->quantity} units added to stock.");
    }
}
