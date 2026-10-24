<?php
namespace App\Models;
class PayrollLine extends TenantModel {
    protected $table = 'payroll_lines';
    public function employee() { return $this->belongsTo(Employee::class); }
}
