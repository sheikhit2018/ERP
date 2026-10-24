<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Inbox of orders arriving from online stores (WooCommerce / Shopify webhooks): imported or failed with a reason, retryable. */
return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable('incoming_orders')) return;
        Schema::create('incoming_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->unique(['tenant_id', 'id']);
            $t->string('source', 20);
            $t->string('external_id', 80);
            $t->string('reference', 80)->nullable();
            $t->json('payload');
            $t->string('status', 16);            // imported | failed | skipped
            $t->string('error', 500)->nullable();
            $t->unsignedBigInteger('sales_order_id')->nullable();
            $t->foreign(['tenant_id', 'sales_order_id'], 'fk_incoming_orders_order')->references(['tenant_id', 'id'])->on('sales_orders')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'source', 'external_id']);
            $t->index(['tenant_id', 'status', 'created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('incoming_orders'); }
};
