<?php
namespace App\Models;
class CourierPaymentLine extends TenantModel {
    protected $table = 'courier_payment_lines';
    public function shipment() { return $this->belongsTo(Shipment::class); }
}
