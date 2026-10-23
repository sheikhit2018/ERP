<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private function tenant(Blueprint $t): void {
        $t->id();
        $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
        $t->unique(['tenant_id', 'id']);
        $t->timestamps();
    }
    private function ref(Blueprint $t, string $column, string $parent): void {
        $t->unsignedBigInteger($column);
        $t->foreign(['tenant_id', $column], 'fk_'.substr(sha1($t->getTable().'_'.$column), 0, 20))->references(['tenant_id', 'id'])->on($parent)->restrictOnDelete();
    }
    public function up(): void {
        Schema::create('tenants', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('slug')->unique();
            $t->boolean('active')->default(true); $t->timestamps();
        });
        Schema::create('tenant_domains', function (Blueprint $t) {
            $this->tenant($t); $t->string('hostname')->unique(); $t->timestamp('verified_at')->nullable();
        });
        // Global login identities: use Laravel's standard users migration.
        Schema::create('tenant_user', function (Blueprint $t) {
            $this->tenant($t); $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('role', 32); $t->boolean('active')->default(true);
            $t->unique(['tenant_id', 'user_id']);
        });
        Schema::create('raw_materials', function (Blueprint $t) {
            $this->tenant($t); $t->string('sku', 80); $t->string('name');
            $t->string('base_unit', 20); // g, ml, mm, piece; quantities are INTEGER base units
            $t->unique(['tenant_id', 'sku']);
        });
        Schema::create('products', function (Blueprint $t) {
            $this->tenant($t); $t->string('sku', 80); $t->string('name');
            $t->string('base_unit', 20)->default('piece'); $t->unique(['tenant_id', 'sku']);
        });
        Schema::create('raw_material_stocks', function (Blueprint $t) {
            $this->tenant($t); $this->ref($t, 'raw_material_id', 'raw_materials');
            $t->bigInteger('quantity')->default(0); $t->unique(['tenant_id', 'raw_material_id']);
        });
        Schema::create('finished_good_stocks', function (Blueprint $t) {
            $this->tenant($t); $this->ref($t, 'product_id', 'products');
            foreach (['available_stock', 'reserved_stock', 'transit_stock', 'damaged_stock'] as $c) $t->bigInteger($c)->default(0);
            $t->unique(['tenant_id', 'product_id']);
        });
        Schema::create('boms', function (Blueprint $t) {
            $this->tenant($t); $this->ref($t, 'product_id', 'products');
            $t->unsignedInteger('version'); $t->boolean('active')->default(true);
            $t->unique(['tenant_id', 'product_id', 'version']);
        });
        Schema::create('bom_items', function (Blueprint $t) {
            $this->tenant($t); $this->ref($t, 'bom_id', 'boms');
            $this->ref($t, 'raw_material_id', 'raw_materials');
            $t->bigInteger('quantity_per_unit');
            $t->unique(['tenant_id', 'bom_id', 'raw_material_id']);
        });
        Schema::create('manufacturing_orders', function (Blueprint $t) {
            $this->tenant($t); $this->ref($t, 'bom_id', 'boms');
            $t->uuid('idempotency_key'); $t->char('request_hash', 64);
            $t->bigInteger('quantity'); $t->string('status', 32)->default('completed');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('completed_at'); $t->unique(['tenant_id', 'idempotency_key']);
        });
        Schema::create('manufacturing_consumptions', function (Blueprint $t) {
            $this->tenant($t); $this->ref($t, 'manufacturing_order_id', 'manufacturing_orders');
            $this->ref($t, 'raw_material_id', 'raw_materials'); $t->bigInteger('quantity');
            $t->unique(['tenant_id', 'manufacturing_order_id', 'raw_material_id'], 'mo_consumption_unique');
        });
        Schema::create('sales_orders', function (Blueprint $t) {
            $this->tenant($t); $t->uuid('idempotency_key'); $t->char('request_hash', 64);
            $t->string('status', 32)->default('pending');
            $t->json('customer'); $t->char('currency', 3)->default('BDT');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unique(['tenant_id', 'idempotency_key']); $t->index(['tenant_id', 'status', 'created_at']);
        });
        Schema::create('sales_order_items', function (Blueprint $t) {
            $this->tenant($t); $this->ref($t, 'sales_order_id', 'sales_orders');
            $this->ref($t, 'product_id', 'products'); $t->bigInteger('quantity');
            $t->bigInteger('unit_price_minor'); // integer paisa/cents
            $t->unique(['tenant_id', 'sales_order_id', 'product_id']);
        });
        Schema::create('shipments', function (Blueprint $t) {
            $this->tenant($t); $this->ref($t, 'sales_order_id', 'sales_orders');
            $t->string('courier', 40); $t->string('tracking_id', 120);
            $t->string('status', 32); $t->timestamp('handed_over_at');
            $t->unique(['tenant_id', 'sales_order_id']); // one full shipment per order in this reference
            $t->unique(['tenant_id', 'courier', 'tracking_id']);
            $t->index(['tenant_id', 'tracking_id']);
        });
        Schema::create('courier_returns', function (Blueprint $t) {
            $this->tenant($t); $this->ref($t, 'shipment_id', 'shipments');
            $t->string('status', 32)->default('pending');
            $t->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->unique(['tenant_id', 'shipment_id']);
        });
        Schema::create('return_items', function (Blueprint $t) {
            $this->tenant($t); $this->ref($t, 'courier_return_id', 'courier_returns');
            $this->ref($t, 'sales_order_item_id', 'sales_order_items'); $t->bigInteger('quantity');
            $t->unique(['tenant_id', 'courier_return_id', 'sales_order_item_id'], 'return_item_unique');
        });
        Schema::create('courier_logs', function (Blueprint $t) {
            $this->tenant($t); $this->ref($t, 'shipment_id', 'shipments');
            $t->string('event', 64); $t->json('payload');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->index(['tenant_id', 'shipment_id', 'created_at']);
        });
        Schema::create('inventory_movements', function (Blueprint $t) {
            $this->tenant($t);
            $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('raw_material_id')->nullable();
            $t->foreign(['tenant_id','product_id'])->references(['tenant_id','id'])->on('products')->restrictOnDelete();
            $t->foreign(['tenant_id','raw_material_id'])->references(['tenant_id','id'])->on('raw_materials')->restrictOnDelete();
            $t->string('bucket', 32); $t->bigInteger('delta');
            $t->string('reason', 64); $t->string('reference', 100);
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->index(['tenant_id', 'product_id', 'created_at']);
        });
        // CHECK supported by PostgreSQL and MySQL >= 8.0.16; do not run this migration on SQLite.
        $checks = [
            'raw_material_stocks' => 'quantity >= 0',
            'finished_good_stocks' => 'available_stock >= 0 AND reserved_stock >= 0 AND transit_stock >= 0 AND damaged_stock >= 0',
            'bom_items' => 'quantity_per_unit > 0',
            'manufacturing_orders' => 'quantity > 0',
            'manufacturing_consumptions' => 'quantity > 0',
            'sales_order_items' => 'quantity > 0 AND unit_price_minor >= 0',
            'return_items' => 'quantity > 0',
            'inventory_movements' => 'delta <> 0 AND ((product_id IS NULL AND raw_material_id IS NOT NULL) OR (product_id IS NOT NULL AND raw_material_id IS NULL))',
        ];
        foreach ($checks as $table => $check) DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_valid CHECK ($check)");
    }
    public function down(): void {
        foreach (['inventory_movements','courier_logs','return_items','courier_returns','shipments','sales_order_items','sales_orders','manufacturing_consumptions','manufacturing_orders','bom_items','boms','finished_good_stocks','raw_material_stocks','products','raw_materials','tenant_user','tenant_domains','tenants'] as $table) Schema::dropIfExists($table);
    }
};
