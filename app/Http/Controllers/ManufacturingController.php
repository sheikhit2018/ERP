<?php
namespace App\Http\Controllers;
use App\Services\InventoryService;
use Illuminate\Http\Request;
class ManufacturingController extends Controller {
    public function store(Request $r, InventoryService $service) {
        $order = $service->manufacture($r->all(), (int)$r->user()->id);
        return response()->json($order->load('bom.product', 'consumptions'), 200);
    }
}
