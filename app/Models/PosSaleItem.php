<?php
namespace App\Models;
class PosSaleItem extends TenantModel {
    protected $table = 'pos_sale_items';
    protected $casts = [];
    public function product() { return $this->belongsTo(Product::class); }
}
