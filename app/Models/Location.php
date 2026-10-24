<?php
namespace App\Models;
class Location extends TenantModel {
    protected $table = 'locations';
    protected $casts = ['is_main' => 'boolean', 'active' => 'boolean'];
}
