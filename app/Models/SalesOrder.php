<?php
namespace App\Models;
class SalesOrder extends TenantModel {
    protected $table = 'sales_orders';
    protected $casts = ['customer'=>'array'];
public function items() { return $this->hasMany(SalesOrderItem::class); }
public function shipment() { return $this->hasOne(Shipment::class); }
public function payments() { return $this->hasMany(OrderPayment::class); }
public function bookings() { return $this->hasMany(CourierBooking::class); }
/** Items + delivery charge − discount, in paisa. */
public function totalMinor(): int {
    $items = (int) $this->items()->sum(\Illuminate\Support\Facades\DB::raw('quantity * unit_price_minor'));
    return max(0, $items + (int) $this->delivery_charge_minor - (int) $this->discount_minor);
}
/** What the courier must still collect: total − advance payments. */
public function codDueMinor(): int { return max(0, $this->totalMinor() - (int) $this->payments()->sum('amount_minor')); }
}
