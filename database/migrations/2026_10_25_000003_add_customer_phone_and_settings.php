<?php
use App\Support\Phone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 *  - sales_orders.customer_phone: canonical phone for customer history / fraud check (indexed).
 *  - tenant_settings: per-company settings and API credentials (values encrypted by the app).
 */
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasColumn('sales_orders', 'customer_phone')) Schema::table('sales_orders', function (Blueprint $t) {
            $t->string('customer_phone', 20)->nullable();
            $t->index(['tenant_id', 'customer_phone']);
        });
        foreach (DB::table('sales_orders')->whereNull('customer_phone')->orderBy('id')->get(['id', 'customer']) as $o) {
            $c = json_decode((string) $o->customer, true) ?: [];
            DB::table('sales_orders')->where('id', $o->id)->update(['customer_phone' => Phone::normalize($c['phone'] ?? '')]);
        }
        if (!Schema::hasTable('tenant_settings')) Schema::create('tenant_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('key', 80);
            $t->text('value')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'key']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('tenant_settings');
        Schema::table('sales_orders', function (Blueprint $t) {
            $t->dropIndex(['tenant_id', 'customer_phone']);
            $t->dropColumn('customer_phone');
        });
    }
};
