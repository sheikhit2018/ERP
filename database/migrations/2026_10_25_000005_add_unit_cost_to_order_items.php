<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/** Cost price frozen on each order line when the order is placed, for profit reports. */
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasColumn('sales_order_items', 'unit_cost_minor')) Schema::table('sales_order_items', fn (Blueprint $t) => $t->bigInteger('unit_cost_minor')->default(0));
        foreach (DB::table('products')->where('cost_minor', '>', 0)->get(['id', 'cost_minor']) as $p) {
            DB::table('sales_order_items')->where('product_id', $p->id)->where('unit_cost_minor', 0)->update(['unit_cost_minor' => $p->cost_minor]);
        }
    }
    public function down(): void {
        Schema::table('sales_order_items', fn (Blueprint $t) => $t->dropColumn('unit_cost_minor'));
    }
};
