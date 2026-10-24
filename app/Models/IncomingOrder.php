<?php
namespace App\Models;
class IncomingOrder extends TenantModel {
    protected $table = 'incoming_orders';
    protected $casts = ['payload' => 'array'];
}
