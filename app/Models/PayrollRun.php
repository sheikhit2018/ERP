<?php
namespace App\Models;
class PayrollRun extends TenantModel {
    protected $table = 'payroll_runs';
    protected $casts = ['paid_on' => 'date'];
    public function lines() { return $this->hasMany(PayrollLine::class); }
}
