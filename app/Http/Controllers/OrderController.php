<?php
namespace App\Http\Controllers;
use App\Services\InventoryService;
use Illuminate\Http\Request;
class OrderController extends Controller {
    public function store(Request $r, InventoryService $s) { return response()->json($s->place($r->all(), (int)$r->user()->id)->load('items.product','shipment')); }
    public function confirm(Request $r, int $order, InventoryService $s) { return response()->json($s->confirm($order)); }
    public function cancel(Request $r, int $order, InventoryService $s) { return response()->json($s->cancel($order, (int)$r->user()->id)); }
    /** scans: [{product_id, quantity}] must equal the order lines exactly. */
    public function pack(Request $r, int $order, InventoryService $s) { return response()->json($s->pack($order, $r->input('scans'), (int)$r->user()->id)); }
    public function handover(Request $r, int $order, InventoryService $s) { return response()->json($s->handover($order, $r->all(), (int)$r->user()->id)->load('shipment')); }
    public function delivered(Request $r, int $order, InventoryService $s) { return response()->json($s->delivered($order, (int)$r->user()->id)); }
}
