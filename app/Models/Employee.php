<?php
namespace App\Models;
class Employee extends TenantModel {
    protected $table = 'employees';
    protected $casts = ['active' => 'boolean', 'join_date' => 'date'];
}
