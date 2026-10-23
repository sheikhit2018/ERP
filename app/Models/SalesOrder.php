<?php
namespace App\Models;
class SalesOrder extends TenantModel {
    protected $table = 'sales_orders';
    protected $casts = ['customer'=>'array'];
public function items() { return $this->hasMany(SalesOrderItem::class); }
public function shipment() { return $this->hasOne(Shipment::class); }
}
