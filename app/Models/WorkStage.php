<?php
namespace App\Models;
class WorkStage extends TenantModel {
    protected $table = 'work_stages';
    protected $casts = ['active' => 'boolean'];
}
