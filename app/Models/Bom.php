<?php
namespace App\Models;
class Bom extends TenantModel {
    protected $table = 'boms';
    public function items() { return $this->hasMany(BomItem::class); }
public function product() { return $this->belongsTo(Product::class); }
}
