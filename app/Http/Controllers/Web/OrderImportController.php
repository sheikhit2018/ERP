<?php
namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\IncomingOrder;
use App\Services\OrderImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Bulk orders from Excel / CSV, and the inbox of orders received from online stores. */
class OrderImportController extends Controller {
    public function index() {
        return Inertia::render('Orders/Import', [
            'inbox' => IncomingOrder::latest('id')->take(100)->get(['id', 'source', 'external_id', 'reference', 'status', 'error', 'sales_order_id', 'created_at']),
            'failed' => IncomingOrder::where('status', 'failed')->count(),
            // Website products ↔ Opryx styles (unlinked first).
            'links' => \App\Models\StoreProductLink::with('style:id,name,code')->orderByRaw('case when product_style_id is null and product_id is null then 0 else 1 end')->orderByDesc('id')->limit(300)->get()
                ->map(fn ($l) => ['id' => $l->id, 'source' => $l->source, 'external_id' => $l->external_id, 'external_name' => $l->external_name, 'style_id' => $l->product_style_id,
                    'style' => $l->style?->name, 'color' => $l->color, 'auto' => $l->auto, 'linked' => $l->linked()]),
            'styles' => \App\Models\ProductStyle::orderBy('name')->get(['id', 'name', 'code'])->map(fn ($st) => ['id' => $st->id, 'name' => $st->name, 'code' => $st->code,
                'colors' => \App\Models\Product::where('style_id', $st->id)->whereNotNull('color')->distinct()->orderBy('color')->pluck('color')]),
            // What the online store sent and what Opryx did with it (newest first).
            'hooks' => \Illuminate\Support\Facades\DB::table('audit_logs')->where('tenant_id', app(\App\Tenancy\TenantContext::class)->id())
                ->where('action', 'like', 'webhook.%')->orderByDesc('id')->limit(30)->get(['id', 'action', 'status', 'meta', 'created_at'])
                ->map(fn ($h) => ['id' => $h->id, 'source' => substr($h->action, 8), 'status' => $h->status, 'created_at' => $h->created_at] + (json_decode((string) $h->meta, true) ?: [])),
        ]);
    }

    /**
     * rows: [{ref, name, phone, address, code, qty, price, delivery, discount, note, source}] — rows with the same ref are one order;
     * without ref every row is its own order. Re-uploading the same file never creates duplicates.
     */
    public function store(Request $r, OrderImportService $s) {
        $d = $r->validate([
            'status' => 'required|in:pending,confirmed', 'rows' => 'required|array|min:1|max:3000',
            'rows.*.ref' => 'nullable|string|max:80', 'rows.*.name' => 'nullable|string|max:150', 'rows.*.phone' => 'nullable|string|max:40',
            'rows.*.address' => 'nullable|string|max:1000', 'rows.*.code' => 'nullable|string|max:80', 'rows.*.qty' => 'nullable|integer|min:1|max:100000',
            'rows.*.price' => 'nullable|integer|min:0', 'rows.*.delivery' => 'nullable|integer|min:0', 'rows.*.discount' => 'nullable|integer|min:0',
            'rows.*.note' => 'nullable|string|max:500', 'rows.*.line' => 'nullable|integer',
        ]);
        $groups = [];
        foreach ($d['rows'] as $i => $row) {
            $k = trim((string) ($row['ref'] ?? '')) !== '' ? 'r:'.trim($row['ref']) : 'l:'.md5(json_encode($row));
            $groups[$k][] = $row + ['line' => $row['line'] ?? $i + 2];
        }
        $created = 0; $existing = 0; $failed = [];
        foreach ($groups as $k => $rows) {
            $h = $rows[0];
            $n = ['source' => 'excel', 'order_source' => 'manual', 'external_id' => $k, 'reference' => $h['ref'] ?? null,
                'customer' => ['name' => (string) ($h['name'] ?? ''), 'phone' => (string) ($h['phone'] ?? ''), 'address' => (string) ($h['address'] ?? '')],
                'lines' => array_map(fn ($x) => ['code' => (string) ($x['code'] ?? ''), 'qty' => (int) ($x['qty'] ?? 1), 'price' => $x['price'] ?? null], $rows),
                'delivery' => (int) ($h['delivery'] ?? 0), 'discount' => (int) ($h['discount'] ?? 0), 'note' => (string) ($h['note'] ?? ''), 'paid' => 0];
            try {
                $o = $s->create($n, (int) $r->user()->id, $d['status']);
                $o->wasRecentlyCreated ? $created++ : $existing++;
            } catch (ValidationException $e) {
                $failed[] = 'Row '.implode(', ', array_column($rows, 'line')).': '.implode(' ', Arr::flatten($e->errors()));
            } catch (HttpExceptionInterface $e) {
                $failed[] = 'Row '.implode(', ', array_column($rows, 'line')).': '.$e->getMessage();
            }
        }
        $msg = "$created orders created".($existing ? ", $existing were already imported" : '').($failed ? ', '.count($failed).' failed (see list)' : '').'.';
        return back()->with($created || $existing ? 'success' : 'error', $msg)->with('report', $failed);
    }

    /** Link one website product to an Opryx style + colour, then retry every waiting website order. */
    public function link(Request $r, int $link, OrderImportService $s) {
        $d = $r->validate(['product_style_id' => 'required|integer', 'color' => 'nullable|string|max:60']);
        $style = \App\Models\ProductStyle::findOrFail($d['product_style_id']);
        $colors = \App\Models\Product::where('style_id', $style->id)->whereNotNull('color')->distinct()->pluck('color');
        if ($colors->count() > 1 && !in_array($d['color'] ?? null, $colors->all(), true)) throw \Illuminate\Validation\ValidationException::withMessages(['inventory' => ['Choose the colour.']]);
        $l = \App\Models\StoreProductLink::findOrFail($link);
        $l->fill(['product_style_id' => $style->id, 'color' => $colors->count() > 1 ? $d['color'] : $colors->first(), 'product_id' => null, 'auto' => false])->save();
        $done = 0; $left = 0;
        foreach (IncomingOrder::where('source', $l->source)->where('status', 'failed')->orderBy('id')->get() as $in) {
            $in = $s->retry($in, (int) $r->user()->id);
            $in->status === 'imported' ? $done++ : $left++;
        }
        return back()->with('success', "Linked “{$l->external_name}” → {$style->name}".($l->color ? " · {$l->color}" : '').". Waiting orders imported: $done".($left ? ", still waiting: $left" : '').'.');
    }

    public function retry(Request $r, int $incoming, OrderImportService $s) {
        $in = $s->retry(IncomingOrder::findOrFail($incoming), (int) $r->user()->id);
        return back()->with($in->status === 'imported' ? 'success' : 'error', $in->status === 'imported' ? "Imported as order #{$in->sales_order_id}." : "Still failing: {$in->error}");
    }
}
