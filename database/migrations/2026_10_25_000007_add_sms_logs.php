<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Every customer SMS: queued → sent / failed (retried by the scheduler). */
return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable('sms_logs')) return;
        Schema::create('sms_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->unique(['tenant_id', 'id']);
            $t->unsignedBigInteger('sales_order_id')->nullable();
            $t->string('event', 20);
            $t->string('phone', 20);
            $t->text('message');
            $t->string('status', 10);   // queued | sent | failed
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->string('error', 300)->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'sales_order_id', 'event']); // one SMS per order per event
            $t->index(['status', 'created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('sms_logs'); }
};
