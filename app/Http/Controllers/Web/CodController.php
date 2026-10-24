<?php
namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\{CourierPayment, OrderPayment, Shipment, User};
use App\Services\CodService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * COD & payments: what couriers collected for delivered parcels vs what they paid us.
 * Expected COD = order total − advance payments (frozen at handover).
 */
class CodController extends Controller {
    public function index(Request $r) {
        $from = Carbon::parse($r->query('from') ?: now()->subDays(59)->toDateString())->startOfDay();
        $to = Carbon::parse($r->query('to') ?: now()->toDateString())->endOfDay();
        $courier = array_key_exists((string) $r->query('courier'), CourierController::COURIERS) ? $r->query('courier') : null;
        $base = fn () => Shipment::query()->whereBetween('handed_over_at', [$from, $to])->when($courier, fn ($q) => $q->where('courier', $courier));
        $t = $base()->selectRaw("
            sum(case when delivered_at is not null or kept_pieces > 0 then 1 else 0 end) as delivered_parcels,
            coalesce(sum(case when delivered_at is not null then cod_expected_minor else 0 end),0) as delivered_expected,
            sum(case when settled_at is not null then 1 else 0 end) as settled_parcels,
            coalesce(sum(case when settled_at is not null then cod_collected_minor else 0 end),0) as collected,
            coalesce(sum(case when settled_at is not null then courier_charge_minor else 0 end),0) as charges,
            sum(case when (delivered_at is not null or kept_pieces > 0) and settled_at is null then 1 else 0 end) as unsettled_parcels,
            coalesce(sum(case when delivered_at is not null and settled_at is null then cod_expected_minor else 0 end),0) as unsettled_expected,
            sum(case when settled_at is not null and delivered_at is not null and cod_collected_minor < cod_expected_minor then 1 else 0 end) as short_parcels,
            coalesce(sum(case when settled_at is not null and delivered_at is not null and cod_collected_minor < cod_expected_minor then cod_expected_minor - cod_collected_minor else 0 end),0) as short_amount")
            ->first();
        $totals = collect($t->getAttributes())->map(fn ($v) => (int) $v)->all();
        $totals['net'] = $totals['collected'] - $totals['charges'];
        $row = fn ($s) => ['id' => $s->id, 'order_id' => $s->sales_order_id, 'courier' => $s->courier, 'tracking_id' => $s->tracking_id,
            'customer' => $s->order->customer['name'] ?? '', 'handed_over_at' => $s->handed_over_at, 'delivered_at' => $s->delivered_at,
            'expected' => (int) $s->cod_expected_minor, 'collected' => $s->cod_collected_minor, 'charge' => $s->courier_charge_minor, 'partial' => $s->kept_pieces > 0];
        $unsettled = $base()->with('order:id,customer')->where(fn ($q) => $q->whereNotNull('delivered_at')->orWhere('kept_pieces', '>', 0))->whereNull('settled_at')
            ->orderBy('delivered_at')->take(300)->get()->map($row);
        $short = $base()->with('order:id,customer')->whereNotNull('settled_at')->whereNotNull('delivered_at')->whereColumn('cod_collected_minor', '<', 'cod_expected_minor')
            ->latest('settled_at')->take(200)->get()->map($row);
        $statements = CourierPayment::when($courier, fn ($q) => $q->where('courier', $courier))->latest('id')->take(30)->get();
        $advance = OrderPayment::whereBetween('received_at', [$from, $to])->selectRaw('method, count(*) as n, sum(amount_minor) as total')->groupBy('method')->get();
        return Inertia::render('Cod/Index', [
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'courier' => $courier],
            'couriers' => CourierController::COURIERS, 'totals' => $totals, 'unsettled' => $unsettled, 'short' => $short, 'statements' => $statements,
            'advance' => $advance, 'users' => User::whereIn('id', $statements->pluck('created_by'))->pluck('name', 'id'),
        ]);
    }

    public function importPage() {
        return Inertia::render('Cod/Import', ['couriers' => CourierController::COURIERS]);
    }
    public function importStore(Request $r, CodService $s) {
        $res = $s->importStatement($r->all(), (int) $r->user()->id);
        $p = $res['payment'];
        $msg = $p ? "Statement saved: {$p->parcel_count} parcels, ৳".number_format($p->net_minor / 100, 2).' net'.($res['delivered'] ? ", {$res['delivered']} marked delivered" : '').'.'
            : 'Nothing saved — no new parcels in this statement.';
        return redirect()->route('cod.index')->with($p ? 'success' : 'error', $msg)->with('report', $res['skipped']);
    }
    public function payment(Request $r, int $order, CodService $s) {
        $s->addPayment($order, $r->all(), (int) $r->user()->id);
        return back()->with('success', 'Payment recorded.');
    }
}
