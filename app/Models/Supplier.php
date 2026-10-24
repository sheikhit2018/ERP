<?php
namespace App\Models;
class Supplier extends TenantModel {
    protected $table = 'suppliers';
    protected $casts = ['active' => 'boolean'];
    public function purchases() { return $this->hasMany(PurchaseOrder::class); }
}
