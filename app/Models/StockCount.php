<?php
namespace App\Models;
class StockCount extends TenantModel {
    protected $table = 'stock_counts';
    protected $casts = ['approved_at' => 'datetime'];
    public function lines() { return $this->hasMany(StockCountLine::class); }
    public function location() { return $this->belongsTo(Location::class); }
}
