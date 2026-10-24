<?php
namespace App\Services\Couriers;

use App\Models\{CourierBooking, SalesOrder, Shipment};
use App\Services\InventoryService;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/** Booking parcels through courier APIs and applying the statuses couriers report back. */
final class CourierApi {
    public const DRIVERS = ['steadfast' => Steadfast::class, 'pathao' => Pathao::class, 'redx' => Redx::class];

    public function __construct(private InventoryService $inventory) {}

    public static function enabled(): array {
        return array_values(array_filter(array_keys(self::DRIVERS), fn ($c) => Settings::get("courier.$c.enabled") === '1'));
    }
    public function driver(string $courier): CourierDriver {
        if (!isset(self::DRIVERS[$courier])) throw new CourierException("$courier has no API connection in Opryx.");
        if (Settings::get("courier.$courier.enabled") !== '1') throw new CourierException(ucfirst($courier).' API is switched off (Settings → Courier API).');
        return app(self::DRIVERS[$courier]);
    }

    /**
     * Create parcels at the courier for confirmed / packed orders. One call per order (no partial state is
     * stored on failure). @return array<int, array{order_id:int, ok:bool, consignment_id?:string, tracking_code?:?string, error?:string}>
     */
    public function book(string $courier, array $orderIds, int $actor): array {
        $driver = $this->driver($courier);
        $out = [];
        foreach (array_slice(array_values(array_unique(array_map('intval', $orderIds))), 0, 50) as $id) {
            try {
                $o = SalesOrder::with('shipment')->find($id);
                if (!$o) throw new CourierException('Order not found.');
                if (!in_array($o->status, ['confirmed', 'packed'], true) || $o->shipment) throw new CourierException("Order is {$o->status}.");
                if ($b = CourierBooking::where('sales_order_id', $o->id)->where('status', 'booked')->first())
                    { $out[] = ['order_id' => $id, 'ok' => true, 'consignment_id' => $b->consignment_id, 'tracking_code' => $b->tracking_code, 'already' => true]; continue; }
                $cod = $o->codDueMinor();
                $r = $driver->book($o, $cod);
                CourierBooking::create(['sales_order_id' => $o->id, 'courier' => $courier, 'consignment_id' => $r['consignment_id'], 'tracking_code' => $r['tracking_code'],
                    'cod_minor' => $cod, 'status' => 'booked', 'created_by' => $actor]);
                $out[] = ['order_id' => $id, 'ok' => true, 'consignment_id' => $r['consignment_id'], 'tracking_code' => $r['tracking_code']];
            } catch (CourierException $e) {
                $out[] = ['order_id' => $id, 'ok' => false, 'error' => $e->getMessage()];
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                $out[] = ['order_id' => $id, 'ok' => false, 'error' => ucfirst($courier).' did not answer. Try again.'];
            }
        }
        return $out;
    }

    /** Forget a booking in Opryx (cancel it in the courier panel too). */
    public function cancelBooking(int $bookingId): void {
        $b = CourierBooking::whereKey($bookingId)->where('status', 'booked')->firstOrFail();
        $b->status = 'cancelled'; $b->save();
    }

    /**
     * Apply a courier-reported state to the parcel. Delivered closes it (stock leaves transit, SMS);
     * partial / returning are only recorded — the warehouse scans the pieces when the parcel arrives.
     * @return string|null what changed
     */
    public function apply(Shipment $s, string $state, string $status, int $actor): ?string {
        $s->forceFill(['courier_status' => mb_substr($status, 0, 60), 'courier_status_at' => now()])->save();
        if ($state === 'delivered' && $s->status === 'shipped') { $this->inventory->delivered((int) $s->sales_order_id, $actor); return 'delivered'; }
        return null;
    }

    /** Find our parcel for a courier identifier (consignment id or tracking code). */
    public static function shipmentFor(string $courier, string $id): ?Shipment {
        return Shipment::where('courier', $courier)->where(fn ($q) => $q->where('tracking_id', $id)->orWhere('alt_tracking', $id))->first();
    }

    /**
     * Ask the couriers about open parcels that were booked through the API (oldest check first).
     * @return array{checked:int, delivered:int, errors:int, last_error:?string}
     */
    public function sync(int $actor, int $limit = 80): array {
        $res = ['checked' => 0, 'delivered' => 0, 'errors' => 0, 'last_error' => null];
        $couriers = self::enabled();
        if (!$couriers) return $res;
        $ships = Shipment::whereIn('courier', $couriers)->where('status', 'shipped')->where('handed_over_at', '>', now()->subDays(60))
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('courier_bookings as b')->whereColumn('b.tenant_id', 'shipments.tenant_id')
                ->whereColumn('b.sales_order_id', 'shipments.sales_order_id')->whereColumn('b.consignment_id', 'shipments.tracking_id'))
            ->orderByRaw('courier_status_at is not null, courier_status_at')->limit($limit)->get();
        foreach ($ships as $s) {
            try {
                $st = $this->driver($s->courier)->status($s->tracking_id);
                $res['checked']++;
                if ($this->apply($s, $st['state'], $st['status'], $actor) === 'delivered') $res['delivered']++;
            } catch (\Throwable $e) {
                $res['errors']++; $res['last_error'] = $e->getMessage();
                $s->forceFill(['courier_status_at' => now()])->save(); // try others first next time
            }
        }
        return $res;
    }
}
