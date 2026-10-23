<?php
namespace App\Models;
class RawMaterialStock extends TenantModel {
    protected $table = 'raw_material_stocks';
    public function material() { return $this->belongsTo(RawMaterial::class, 'raw_material_id'); }
}
