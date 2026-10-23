<?php
namespace App\Models;
class SalesOrderItem extends TenantModel {
    protected $table = 'sales_order_items';
    public function product() { return $this->belongsTo(Product::class); }
}
