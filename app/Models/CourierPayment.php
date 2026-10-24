<?php
namespace App\Models;
class CourierPayment extends TenantModel {
    protected $table = 'courier_payments';
    protected $casts = ['paid_on' => 'date'];
    public function lines() { return $this->hasMany(CourierPaymentLine::class); }
}
