<?php
namespace App\Services\Couriers;

use App\Models\SalesOrder;

/**
 * One courier's merchant API. Normalised parcel states:
 *  pending (booked, not picked) · in_transit · delivered · partial (partly delivered, rest coming back)
 *  · returning (cancelled / returned to merchant) · hold · unknown
 */
interface CourierDriver {
    /** @return array{consignment_id: string, tracking_code: ?string, raw: array} — throws CourierException */
    public function book(SalesOrder $order, int $codMinor): array;
    /** @return array{state: string, status: string} for one consignment id */
    public function status(string $consignmentId): array;
    /** Human-readable check of the saved credentials. */
    public function test(): string;
}
