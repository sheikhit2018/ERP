<?php
namespace App\Models;
class CourierBatch extends TenantModel {
    protected $table = 'courier_batches';
    public function shipments() { return $this->hasMany(Shipment::class, 'dispatch_batch_id'); }
    public function returns() { return $this->hasMany(CourierReturn::class, 'return_batch_id'); }
}
