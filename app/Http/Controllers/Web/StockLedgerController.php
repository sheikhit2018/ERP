<?php
namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\{InventoryMovement, Product, RawMaterial};
use Illuminate\Http\Request;
use Inertia\Inertia;

class StockLedgerController extends Controller {
    public function __invoke(Request $r) {
        $movements = InventoryMovement::query()
            ->when($r->integer('product_id'), fn ($q, $id) => $q->where('product_id', $id))
            ->latest('id')->paginate(50)->withQueryString();
        $pids = $movements->pluck('product_id')->filter()->unique();
        $mids = $movements->pluck('raw_material_id')->filter()->unique();
        return Inertia::render('Stock/Ledger', [
            'movements' => $movements,
            'products' => Product::whereIn('id', $pids)->pluck('name', 'id'),
            'materials' => RawMaterial::whereIn('id', $mids)->pluck('name', 'id'),
            'users' => \App\Models\User::whereIn('id', $movements->pluck('actor_id')->unique())->pluck('name', 'id'),
        ]);
    }
}
