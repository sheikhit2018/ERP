<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Employees, attendance, production work log (piece rate), salary advances and monthly payroll. */
return new class extends Migration {
    private function tenant(Blueprint $t): void { $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete(); $t->unique(['tenant_id', 'id']); $t->timestamps(); }
    private function ref(Blueprint $t, string $col, string $parent, bool $null = false): void {
        $null ? $t->unsignedBigInteger($col)->nullable() : $t->unsignedBigInteger($col);
        $t->foreign(['tenant_id', $col], 'fk_'.substr(sha1($t->getTable().'_'.$col), 0, 20))->references(['tenant_id', 'id'])->on($parent)->restrictOnDelete();
    }
    public function up(): void {
        if (!Schema::hasTable('employees')) Schema::create('employees', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('name', 120); $t->string('phone', 40)->nullable(); $t->string('designation', 80)->nullable(); $t->string('department', 40)->nullable();
            $t->string('pay_type', 10);                   // monthly | daily | piece
            $t->bigInteger('monthly_salary_minor')->default(0); $t->bigInteger('daily_wage_minor')->default(0);
            $t->date('join_date')->nullable(); $t->boolean('active')->default(true);
        });
        if (!Schema::hasTable('attendances')) Schema::create('attendances', function (Blueprint $t) {
            $this->tenant($t); $this->ref($t, 'employee_id', 'employees');
            $t->date('work_date'); $t->string('status', 8);   // present | absent | leave | half
            $t->unique(['tenant_id', 'employee_id', 'work_date']);
            $t->index(['tenant_id', 'work_date']);
        });
        if (!Schema::hasTable('work_stages')) Schema::create('work_stages', function (Blueprint $t) {
            $this->tenant($t); $t->string('name', 60); $t->bigInteger('rate_minor')->default(0); $t->unsignedSmallInteger('sort')->default(0); $t->boolean('active')->default(true);
        });
        if (!Schema::hasTable('work_logs')) Schema::create('work_logs', function (Blueprint $t) {
            $this->tenant($t); $this->ref($t, 'employee_id', 'employees'); $this->ref($t, 'work_stage_id', 'work_stages');
            $this->ref($t, 'product_style_id', 'product_styles', true);
            $t->date('work_date'); $t->unsignedInteger('quantity'); $t->bigInteger('rate_minor');
            $t->string('note', 200)->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->index(['tenant_id', 'work_date']); $t->index(['tenant_id', 'employee_id', 'work_date']);
        });
        if (!Schema::hasTable('salary_advances')) Schema::create('salary_advances', function (Blueprint $t) {
            $this->tenant($t); $this->ref($t, 'employee_id', 'employees');
            $t->date('paid_on'); $t->bigInteger('amount_minor'); $t->string('note', 200)->nullable();
            $t->unsignedBigInteger('payroll_line_id')->nullable();   // set when deducted
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
        });
        if (!Schema::hasTable('payroll_runs')) Schema::create('payroll_runs', function (Blueprint $t) {
            $this->tenant($t); $t->char('month', 7); $t->string('status', 8);   // draft | paid
            $t->unsignedBigInteger('money_account_id')->nullable(); $t->date('paid_on')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unique(['tenant_id', 'month']);
        });
        if (!Schema::hasTable('payroll_lines')) Schema::create('payroll_lines', function (Blueprint $t) {
            $this->tenant($t); $this->ref($t, 'payroll_run_id', 'payroll_runs'); $this->ref($t, 'employee_id', 'employees');
            $t->string('pay_type', 10); $t->decimal('days_worked', 6, 1)->default(0); $t->decimal('days_absent', 6, 1)->default(0); $t->unsignedInteger('pieces')->default(0);
            $t->bigInteger('base_minor')->default(0); $t->bigInteger('piece_minor')->default(0); $t->bigInteger('bonus_minor')->default(0);
            $t->bigInteger('deduction_minor')->default(0); $t->bigInteger('advance_minor')->default(0); $t->bigInteger('net_minor')->default(0);
            $t->unique(['tenant_id', 'payroll_run_id', 'employee_id']);
        });
    }
    public function down(): void { foreach (['payroll_lines', 'payroll_runs', 'salary_advances', 'work_logs', 'work_stages', 'attendances', 'employees'] as $x) Schema::dropIfExists($x); }
};
