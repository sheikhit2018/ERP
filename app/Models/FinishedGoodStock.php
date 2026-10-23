<?php
namespace App\Models;
class FinishedGoodStock extends TenantModel {
    protected $table = 'finished_good_stocks';
    protected $appends = ['on_hand_stock'];
public function getOnHandStockAttribute(): int { return (int)$this->available_stock + (int)$this->reserved_stock; }
public function product() { return $this->belongsTo(Product::class); }
}
