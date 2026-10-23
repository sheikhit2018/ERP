<?php
namespace App\Models;
class Product extends TenantModel {
    protected $table = 'products';
    public function stock() { return $this->hasOne(FinishedGoodStock::class); }
public function boms() { return $this->hasMany(Bom::class); }
}
