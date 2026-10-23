<?php
namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\RawMaterial;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RawMaterialController extends Controller {
    public function index() {
        return Inertia::render('Materials/Index', ['materials' => RawMaterial::with('stock')->orderBy('name')->get()]);
    }
    public function store(Request $r, InventoryService $s) {
        $m = $s->createRawMaterial($r->all(), (int) $r->user()->id);
        return back()->with('success', "Raw material {$m->name} created.");
    }
    public function adjust(Request $r, int $material, InventoryService $s) {
        $s->adjustRawMaterial($material, $r->all(), (int) $r->user()->id);
        return back()->with('success', 'Raw material stock updated.');
    }
}
