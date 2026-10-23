<?php
namespace App\Models;
class ReturnItem extends TenantModel {
    protected $table = 'return_items';
    public function orderItem() { return $this->belongsTo(SalesOrderItem::class, 'sales_order_item_id'); }
}
