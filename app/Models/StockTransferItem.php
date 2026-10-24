<?php
namespace App\Models;
class StockTransferItem extends TenantModel {
    protected $table = 'stock_transfer_items';
    protected $casts = [];
    public function product() { return $this->belongsTo(Product::class); }
}
