<?php
namespace App\Models;
class OrderPayment extends TenantModel {
    protected $table = 'order_payments';
    protected $casts = ['received_at' => 'datetime'];
    public function order() { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
}
