<?php
namespace App\Models;
class ProductStyle extends TenantModel {
    protected $table = 'product_styles';
    public function variants() { return $this->hasMany(Product::class, 'style_id'); }
}
