<?php
namespace App\Models;
class PurchaseItem extends TenantModel {
    protected $table = 'purchase_items';
    public function product() { return $this->belongsTo(Product::class); }
    public function material() { return $this->belongsTo(RawMaterial::class, 'raw_material_id'); }
}
