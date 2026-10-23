<?php
namespace App\Models;
class BomItem extends TenantModel {
    protected $table = 'bom_items';
    public function material() { return $this->belongsTo(RawMaterial::class, 'raw_material_id'); }
}
