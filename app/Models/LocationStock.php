<?php
namespace App\Models;
class LocationStock extends TenantModel {
    protected $table = 'location_stocks';
    protected $casts = [];
    public function product() { return $this->belongsTo(Product::class); }
}
