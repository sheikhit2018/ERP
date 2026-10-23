<?php
namespace App\Models;
class Shipment extends TenantModel {
    protected $table = 'shipments';
    public function order() { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
public function courierReturn() { return $this->hasOne(CourierReturn::class); }
}
