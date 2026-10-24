<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Courier API.
 *  - courier_bookings: parcel created at the courier through its API before the physical handover
 *    (consignment id + tracking code). One active booking per order.
 *  - shipments.alt_tracking: the courier's second identifier (e.g. Steadfast tracking code vs consignment id),
 *    so a scan of either finds the parcel.
 *  - shipments.courier_status / courier_status_at: last status reported by the courier (webhook or sync).
 */
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('courier_bookings')) Schema::create('courier_bookings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->unique(['tenant_id', 'id']);
            $t->unsignedBigInteger('sales_order_id');
            $t->foreign(['tenant_id', 'sales_order_id'], 'fk_courier_bookings_order')->references(['tenant_id', 'id'])->on('sales_orders')->restrictOnDelete();
            $t->string('courier', 40);
            $t->string('consignment_id', 120);
            $t->string('tracking_code', 120)->nullable();
            $t->bigInteger('cod_minor');
            $t->string('status', 16)->default('booked'); // booked | handed_over | cancelled
            $t->string('courier_status', 60)->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'courier', 'consignment_id']);
            $t->index(['tenant_id', 'sales_order_id', 'status']);
            $t->index(['tenant_id', 'tracking_code']);
        });
        if (!Schema::hasColumn('shipments', 'alt_tracking')) Schema::table('shipments', function (Blueprint $t) {
            $t->string('alt_tracking', 120)->nullable();
            $t->string('courier_status', 60)->nullable();
            $t->timestamp('courier_status_at')->nullable();
            $t->index(['tenant_id', 'alt_tracking']);
        });
    }
    public function down(): void {
        Schema::table('shipments', function (Blueprint $t) {
            $t->dropIndex(['tenant_id', 'alt_tracking']);
            $t->dropColumn(['alt_tracking', 'courier_status', 'courier_status_at']);
        });
        Schema::dropIfExists('courier_bookings');
    }
};
