<?php
namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Reports: sales, profit, products & sizes, couriers, stock value. Every table exports to CSV (opens in Excel). */
class ReportController extends Controller {
    public const TABS = ['sales', 'profit', 'products', 'couriers', 'stock'];

    public function __invoke(Request $r) {
        $tab = in_array($r->query('tab'), self::TABS, true) ? $r->query('tab') : 'sales';
        $from = Carbon::parse($r->query('from') ?: now()->subDays(29)->toDateString())->startOfDay();
        $to = Carbon::parse($r->query('to') ?: now()->toDateString())->endOfDay();
        $svc = new ReportService($from, $to);
        $data = match ($tab) {
            'sales' => $svc->sales(), 'profit' => $svc->profit(), 'products' => $svc->products(),
            'couriers' => ['rows' => $svc->couriers()], 'stock' => $svc->stock(max(7, min(365, (int) ($r->query('dead') ?: 60)))),
        };
        if ($r->query('export') === 'csv') return $this->csv("opryx-$tab-{$from->toDateString()}-{$to->toDateString()}.csv", $tab, $data);
        return Inertia::render('Reports/Index', ['tab' => $tab, 'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'dead' => (int) ($r->query('dead') ?: 60)],
            'data' => $data, 'couriers' => CourierController::COURIERS]);
    }

    private function csv(string $name, string $tab, array $data): StreamedResponse {
        $tk = fn ($m) => number_format(((int) $m) / 100, 2, '.', '');
        [$head, $rows] = match ($tab) {
            'sales' => [['Date', 'Orders', 'Pieces', 'Value (BDT)'], collect($data['days'])->map(fn ($d) => [$d['day'], $d['orders'], $d['pieces'], $tk($d['value'])])],
            'profit' => [['Item', 'Amount (BDT)'], collect(['revenue' => 'Product sales', 'delivery_income' => 'Delivery charge collected', 'discount' => 'Discount (-)',
                'cogs' => 'Cost of goods (-)', 'courier_charges' => 'Courier charges (-)', 'gross_profit' => 'Gross profit', 'damaged_cost' => 'Damaged returns at cost (-)',
                'lost_cost' => 'Lost / missing at cost (-)', 'net_after_losses' => 'Profit after losses'])->map(fn ($l, $k) => [$l, $tk($data[$k])])->values()],
            'products' => [['Product', 'Colour', 'Size', 'SKU', 'Sold', 'Returned', 'Return %', 'Revenue (BDT)', 'Profit (BDT)'],
                collect($data['rows'])->map(fn ($p) => [$p['name'], $p['color'], $p['size'], $p['sku'], $p['qty'], $p['returned'], $p['return_rate'], $tk($p['revenue']), $tk($p['profit'])])],
            'couriers' => [['Courier', 'Parcels', 'Pieces', 'Delivered', 'Returned', 'Lost', 'With courier', 'Success %', 'Avg days', 'Charges (BDT)'],
                collect($data['rows'])->map(fn ($c) => [$c['courier'], $c['sent'], $c['pieces'], $c['delivered'], $c['returned'], $c['lost'], $c['open'], $c['success'], $c['avg_days'], $tk($c['charges'])])],
            'stock' => [['Product', 'Colour', 'Size', 'SKU', 'Available', 'Reserved', 'Transit', 'Damaged', 'Cost value (BDT)', 'Sale value (BDT)', 'Last sold', 'Dead stock'],
                collect($data['rows'])->map(fn ($s) => [$s['name'], $s['color'], $s['size'], $s['sku'], $s['available'], $s['reserved'], $s['transit'], $s['damaged'],
                    $tk($s['cost_value']), $tk($s['sale_value']), $s['last_sold'], $s['dead'] ? 'yes' : ''])],
        };
        return response()->streamDownload(function () use ($head, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Bangla correctly
            fputcsv($out, $head);
            foreach ($rows as $row) fputcsv($out, array_map(fn ($v) => is_string($v) && !is_numeric($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, $row));
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
