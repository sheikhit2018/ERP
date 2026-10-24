<?php
namespace App\Models;
class CourierBooking extends TenantModel {
    protected $table = 'courier_bookings';
    public function order() { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
}
