<?php
namespace App\Models;
class CourierLog extends TenantModel {
    protected $table = 'courier_logs';
    protected $casts = ['payload'=>'array'];
}
