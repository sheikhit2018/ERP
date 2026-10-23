<?php
namespace App\Models;
class CourierReturn extends TenantModel {
    protected $table = 'courier_returns';
    public function shipment() { return $this->belongsTo(Shipment::class); }
public function items() { return $this->hasMany(ReturnItem::class); }
}
