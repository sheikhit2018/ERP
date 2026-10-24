<?php
namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use App\Services\CustomerRisk;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** Customers = everyone who ordered, grouped by phone, with delivery history (fraud check). */
class CustomerController extends Controller {
    public function index(Request $r) {
        $q = trim((string) $r->query('q', ''));
        $page = SalesOrder::query()->whereNotNull('customer_phone')->where('customer_phone', '!=', '')
            ->when($q !== '', function ($w) use ($q) {
                $digits = Phone::normalize($q);
                $w->where(fn ($x) => $x->when($digits !== '', fn ($y) => $y->where('customer_phone', 'like', "%$digits%"))
                    ->orWhereRaw(DB::getDriverName() === 'pgsql' ? 'customer::text ilike ?' : 'customer like ?', ['%'.$q.'%']));
            })
            ->groupBy('customer_phone')->select('customer_phone')->selectRaw('max(id) as last_id, count(*) as orders')
            ->orderByDesc('last_id')->paginate(50)->withQueryString();
        $phones = $page->getCollection()->pluck('customer_phone')->all();
        $risk = CustomerRisk::forPhones($phones);
        $last = SalesOrder::whereIn('id', $page->getCollection()->pluck('last_id'))->get(['id', 'customer', 'customer_phone'])->keyBy('customer_phone');
        $spend = DB::table('sales_order_items as i')->join('sales_orders as o', fn ($j) => $j->on('o.id', '=', 'i.sales_order_id')->on('o.tenant_id', '=', 'i.tenant_id'))
            ->where('o.tenant_id', app(\App\Tenancy\TenantContext::class)->id())->whereIn('o.customer_phone', $phones)->whereIn('o.status', CustomerRisk::DELIVERED)
            ->groupBy('o.customer_phone')->selectRaw('o.customer_phone, sum(i.quantity * i.unit_price_minor) as total')->pluck('total', 'customer_phone');
        $rows = $page->getCollection()->map(fn ($c) => ['phone' => $c->customer_phone, 'name' => $last[$c->customer_phone]->customer['name'] ?? '',
            'address' => $last[$c->customer_phone]->customer['address'] ?? '', 'last_order_id' => (int) $c->last_id,
            'spent_minor' => (int) ($spend[$c->customer_phone] ?? 0)] + $risk[$c->customer_phone])
            ->values();
        $page->setCollection($rows);
        return Inertia::render('Customers/Index', ['customers' => $page, 'q' => $q]);
    }

    /** JSON for the order form and order page: history of this phone number. */
    public function risk(Request $r) {
        $phone = (string) $r->query('phone', '');
        abort_if(strlen(Phone::normalize($phone)) < 6, 422, 'Enter a phone number.');
        $except = $r->integer('except') ?: null;
        $recent = SalesOrder::where('customer_phone', Phone::normalize($phone))->when($except, fn ($q) => $q->whereKeyNot($except))
            ->latest('id')->take(10)->get(['id', 'status', 'created_at', 'customer']);
        return response()->json(CustomerRisk::forPhone($phone, $except) + ['recent' => $recent->map(fn ($o) => ['id' => $o->id, 'status' => $o->status, 'created_at' => $o->created_at, 'name' => $o->customer['name'] ?? ''])]);
    }
}
