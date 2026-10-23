<?php
namespace App\Models;
class ManufacturingOrder extends TenantModel {
    protected $table = 'manufacturing_orders';
    public function bom() { return $this->belongsTo(Bom::class); }
public function consumptions() { return $this->hasMany(ManufacturingConsumption::class); }
}
