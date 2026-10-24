<?php
namespace App\Models;
class StockTransfer extends TenantModel {
    protected $table = 'stock_transfers';
    protected $casts = [];
    public function items() { return $this->hasMany(StockTransferItem::class); }
    public function from() { return $this->belongsTo(Location::class, "from_location_id"); }
    public function to() { return $this->belongsTo(Location::class, "to_location_id"); }
}
