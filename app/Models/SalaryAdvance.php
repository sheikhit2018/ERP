<?php
namespace App\Models;
class SalaryAdvance extends TenantModel {
    protected $table = 'salary_advances';
    protected $casts = ['paid_on' => 'date'];
}
