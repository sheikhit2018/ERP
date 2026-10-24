<?php
namespace App\Models;
class PurchaseOrder extends TenantModel {
    protected $table = 'purchase_orders';
    protected $casts = ['order_date' => 'date', 'expected_date' => 'date'];
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function items() { return $this->hasMany(PurchaseItem::class); }
    public function receipts() { return $this->hasMany(PurchaseReceipt::class); }
}
