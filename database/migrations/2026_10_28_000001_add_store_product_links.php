<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Website product (WooCommerce / Shopify product or variation id) → Opryx style + colour (size comes from the order) or one exact variant. */
return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable('store_product_links')) return;
        Schema::create('store_product_links', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete(); $t->unique(['tenant_id', 'id']);
            $t->string('source', 16); $t->string('external_id', 40); $t->string('external_name', 255)->nullable();
            $t->unsignedBigInteger('product_style_id')->nullable(); $t->string('color', 60)->nullable(); $t->unsignedBigInteger('product_id')->nullable();
            $t->boolean('auto')->default(false); $t->timestamps();
            $t->unique(['tenant_id', 'source', 'external_id']);
            $t->foreign(['tenant_id', 'product_style_id'], 'fk_spl_style')->references(['tenant_id', 'id'])->on('product_styles')->restrictOnDelete();
            $t->foreign(['tenant_id', 'product_id'], 'fk_spl_product')->references(['tenant_id', 'id'])->on('products')->restrictOnDelete();
        });
    }
    public function down(): void { Schema::dropIfExists('store_product_links'); }
};
