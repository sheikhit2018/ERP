<?php
namespace App\Models;
class PosSale extends TenantModel {
    protected $table = 'pos_sales';
    protected $casts = ['voided_at' => 'datetime'];
    public function items() { return $this->hasMany(PosSaleItem::class); }
    public function location() { return $this->belongsTo(Location::class); }
}
