<?php
namespace App\Models;
class StockCountLine extends TenantModel {
    protected $table = 'stock_count_lines';
    protected $casts = [];
    public function product() { return $this->belongsTo(Product::class); }
}
