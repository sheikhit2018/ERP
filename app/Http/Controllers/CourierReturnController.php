<?php
namespace App\Http\Controllers;
use App\Services\InventoryService;
use App\Models\CourierReturn;
use Illuminate\Http\Request;
class CourierReturnController extends Controller {
    private function details(CourierReturn $return) {
        return response()->json($return->load('shipment.order.items.product'));
    }
    public function scan(Request $r, InventoryService $s) { return $this->details($s->scan($r->all(), (int)$r->user()->id)); }
    public function qc(Request $r, int $return, InventoryService $s) {
        $d = $r->validate(['decision'=>'required|in:QC_PASSED,DAMAGED']);
        return $this->details($s->qc($return, $d['decision'], (int)$r->user()->id, $r->has('scans') ? (array)$r->input('scans') : null));
    }
}
