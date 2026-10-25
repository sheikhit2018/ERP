<?php
namespace App\Models;
class StoreProductLink extends TenantModel {
    protected $table = 'store_product_links';
    protected $casts = ['auto' => 'boolean'];
    public function style() { return $this->belongsTo(ProductStyle::class, 'product_style_id'); }
    public function product() { return $this->belongsTo(Product::class); }
    public function linked(): bool { return $this->product_style_id !== null || $this->product_id !== null; }
}
