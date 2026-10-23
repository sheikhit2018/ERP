<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase-1 additions on top of the reference schema: prices, low-stock level, order totals. */
return new class extends Migration {
    public function up(): void {
        Schema::table('products', function (Blueprint $t) {
            $t->bigInteger('price_minor')->default(0);      // sale price in paisa
            $t->bigInteger('cost_minor')->default(0);       // cost price in paisa
            $t->bigInteger('low_stock_level')->default(5);
            $t->boolean('active')->default(true);
        });
        Schema::table('raw_materials', function (Blueprint $t) {
            $t->bigInteger('low_stock_level')->default(0);
        });
        Schema::table('sales_orders', function (Blueprint $t) {
            $t->bigInteger('delivery_charge_minor')->default(0);
            $t->bigInteger('discount_minor')->default(0);
            $t->string('source', 30)->default('manual');    // manual, website, facebook, phone
            $t->text('note')->nullable();
        });
        Schema::table('tenants', function (Blueprint $t) {
            $t->string('phone', 40)->nullable();
            $t->string('address', 500)->nullable();
        });
    }
    public function down(): void {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['price_minor', 'cost_minor', 'low_stock_level', 'active']));
        Schema::table('raw_materials', fn (Blueprint $t) => $t->dropColumn(['low_stock_level']));
        Schema::table('sales_orders', fn (Blueprint $t) => $t->dropColumn(['delivery_charge_minor', 'discount_minor', 'source', 'note']));
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn(['phone', 'address']));
    }
};
