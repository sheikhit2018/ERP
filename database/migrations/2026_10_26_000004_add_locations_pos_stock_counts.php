<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Stock locations (main warehouse + shops / outlets), transfers, point-of-sale and physical stock counts. */
return new class extends Migration {
    private function tenant(Blueprint $t): void { $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete(); $t->unique(['tenant_id', 'id']); $t->timestamps(); }
    private function ref(Blueprint $t, string $col, string $parent, bool $null = false): void {
        $null ? $t->unsignedBigInteger($col)->nullable() : $t->unsignedBigInteger($col);
        $t->foreign(['tenant_id', $col], 'fk_'.substr(sha1($t->getTable().'_'.$col), 0, 20))->references(['tenant_id', 'id'])->on($parent)->restrictOnDelete();
    }
    public function up(): void {
        if (!Schema::hasTable('locations')) Schema::create('locations', function (Blueprint $t) {
            $this->tenant($t); $t->string('name', 80); $t->string('type', 12); // warehouse | shop
            $t->boolean('is_main')->default(false); $t->string('address', 200)->nullable(); $t->boolean('active')->default(true);
            $t->unique(['tenant_id', 'name']);
        });
        // Stock held at non-main locations. Main warehouse = available stock − everything held elsewhere.
        if (!Schema::hasTable('location_stocks')) Schema::create('location_stocks', function (Blueprint $t) {
            $this->tenant($t); $this->ref($t, 'location_id', 'locations'); $this->ref($t, 'product_id', 'products');
            $t->unsignedInteger('quantity')->default(0); $t->unique(['tenant_id', 'location_id', 'product_id']); $t->index(['tenant_id', 'product_id']);
        });
        if (!Schema::hasTable('stock_transfers')) Schema::create('stock_transfers', function (Blueprint $t) {
            $this->tenant($t); $this->ref($t, 'from_location_id', 'locations'); $this->ref($t, 'to_location_id', 'locations');
            $t->uuid('idempotency_key'); $t->unique(['tenant_id', 'idempotency_key']);
            $t->unsignedInteger('piece_count'); $t->string('note', 200)->nullable(); $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
        });
        if (!Schema::hasTable('stock_transfer_items')) Schema::create('stock_transfer_items', function (Blueprint $t) {
            $this->tenant($t); $this->ref($t, 'stock_transfer_id', 'stock_transfers'); $this->ref($t, 'product_id', 'products'); $t->unsignedInteger('quantity');
        });
        if (!Schema::hasTable('pos_sales')) Schema::create('pos_sales', function (Blueprint $t) {
            $this->tenant($t); $this->ref($t, 'location_id', 'locations'); $this->ref($t, 'money_account_id', 'money_accounts', true);
            $t->uuid('idempotency_key'); $t->unique(['tenant_id', 'idempotency_key']); $t->string('request_hash', 64);
            $t->string('status', 8)->default('paid'); // paid | void
            $t->string('customer_name', 120)->nullable(); $t->string('customer_phone', 20)->nullable();
            $t->bigInteger('subtotal_minor'); $t->bigInteger('discount_minor')->default(0); $t->bigInteger('total_minor'); $t->bigInteger('cost_minor')->default(0);
            $t->string('method', 10); $t->bigInteger('tendered_minor')->default(0);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamp('voided_at')->nullable();
            $t->index(['tenant_id', 'created_at']);
        });
        if (!Schema::hasTable('pos_sale_items')) Schema::create('pos_sale_items', function (Blueprint $t) {
            $this->tenant($t); $this->ref($t, 'pos_sale_id', 'pos_sales'); $this->ref($t, 'product_id', 'products');
            $t->unsignedInteger('quantity'); $t->bigInteger('unit_price_minor'); $t->bigInteger('unit_cost_minor')->default(0);
        });
        if (!Schema::hasTable('stock_counts')) Schema::create('stock_counts', function (Blueprint $t) {
            $this->tenant($t); $this->ref($t, 'location_id', 'locations'); $t->string('status', 10)->default('open'); // open | approved | cancelled
            $t->string('note', 200)->nullable(); $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('approved_by')->nullable(); $t->timestamp('approved_at')->nullable();
        });
        if (!Schema::hasTable('stock_count_lines')) Schema::create('stock_count_lines', function (Blueprint $t) {
            $this->tenant($t); $this->ref($t, 'stock_count_id', 'stock_counts'); $this->ref($t, 'product_id', 'products');
            $t->integer('system_qty')->default(0); $t->unsignedInteger('counted_qty')->default(0);
            $t->unique(['tenant_id', 'stock_count_id', 'product_id']);
        });
    }
    public function down(): void {
        foreach (['stock_count_lines', 'stock_counts', 'pos_sale_items', 'pos_sales', 'stock_transfer_items', 'stock_transfers', 'location_stocks', 'locations'] as $t) Schema::dropIfExists($t);
    }
};
