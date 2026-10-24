<?php
namespace App\Models;
class Shipment extends TenantModel {
    protected $table = 'shipments';
    public function order() { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
protected $casts = ['handed_over_at'=>'datetime', 'delivered_at'=>'datetime', 'returned_at'=>'datetime', 'lost_at'=>'datetime'];
    public function batch() { return $this->belongsTo(CourierBatch::class, 'dispatch_batch_id'); }
    public function courierReturn() { return $this->hasOne(CourierReturn::class); }
}
