<?php
namespace App\Models;
class RawMaterial extends TenantModel {
    protected $table = 'raw_materials';
    public function stock() { return $this->hasOne(RawMaterialStock::class); }
}
