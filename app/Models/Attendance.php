<?php
namespace App\Models;
class Attendance extends TenantModel {
    protected $table = 'attendances';
    protected $casts = ['work_date' => 'date'];
}
