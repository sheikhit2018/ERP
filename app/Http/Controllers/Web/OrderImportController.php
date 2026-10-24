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

    public function retry(Request $r, int $incoming, OrderImportService $s) {
        $in = $s->retry(IncomingOrder::findOrFail($incoming), (int) $r->user()->id);
        return back()->with($in->status === 'imported' ? 'success' : 'error', $in->status === 'imported' ? "Imported as order #{$in->sales_order_id}." : "Still failing: {$in->error}");
    }
}
