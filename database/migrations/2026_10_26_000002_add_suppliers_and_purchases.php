<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Suppliers, purchase orders (products or raw materials) and goods received against them. */
return new class extends Migration {
    private function tenant(Blueprint $t): void { $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete(); $t->unique(['tenant_id', 'id']); $t->timestamps(); }
    private function ref(Blueprint $t, string $col, string $parent, bool $null = false): void {
        $null ? $t->unsignedBigInteger($col)->nullable() : $t->unsignedBigInteger($col);
        $t->foreign(['tenant_id', $col], 'fk_'.substr(sha1($t->getTable().'_'.$col), 0, 20))->references(['tenant_id', 'id'])->on($parent)->restrictOnDelete();
    }
    public function up(): void {
        if (!Schema::hasTable('suppliers')) Schema::create('suppliers', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('name', 150); $t->string('phone', 40)->nullable(); $t->string('address', 500)->nullable(); $t->string('note', 300)->nullable();
            $t->bigInteger('opening_due_minor')->default(0);
            $t->boolean('active')->default(true);
        });
        if (!Schema::hasTable('purchase_orders')) Schema::create('purchase_orders', function (Blueprint $t) {
            $this->tenant($t); $this->ref($t, 'supplier_id', 'suppliers');
            $t->string('status', 12);     // ordered | partial | received | cancelled
            $t->date('order_date'); $t->date('expected_date')->nullable();
            $t->string('note', 300)->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->index(['tenant_id', 'status']);
        });
        if (!Schema::hasTable('purchase_items')) Schema::create('purchase_items', function (Blueprint $t) {
            $this->tenant($t); $this->ref($t, 'purchase_order_id', 'purchase_orders');
            $this->ref($t, 'product_id', 'products', true); $this->ref($t, 'raw_material_id', 'raw_materials', true);
            $t->bigInteger('quantity'); $t->bigInteger('unit_cost_minor'); $t->bigInteger('received_quantity')->default(0);
        });
        if (!Schema::hasTable('purchase_receipts')) Schema::create('purchase_receipts', function (Blueprint $t) {
            $this->tenant($t); $this->ref($t, 'purchase_order_id', 'purchase_orders');
            $t->date('received_on'); $t->bigInteger('value_minor'); $t->string('note', 300)->nullable();
            $t->uuid('idempotency_key'); $t->unique(['tenant_id', 'idempotency_key']);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
        });
    }
    public function down(): void { foreach (['purchase_receipts', 'purchase_items', 'purchase_orders', 'suppliers'] as $x) Schema::dropIfExists($x); }
};
