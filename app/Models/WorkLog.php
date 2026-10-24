<?php
namespace App\Models;
class WorkLog extends TenantModel {
    protected $table = 'work_logs';
    protected $casts = ['work_date' => 'date'];
    public function employee() { return $this->belongsTo(Employee::class); }
    public function stage() { return $this->belongsTo(WorkStage::class, 'work_stage_id'); }
}
