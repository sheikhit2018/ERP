<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Money side of courier parcels.
 *  - order_payments: money received outside COD (advance via bKash / Nagad / bank / cash).
 *  - shipments.cod_expected_minor: what the courier must collect (order total − advance) frozen at handover;
 *    cod_collected_minor / courier_charge_minor / settled_at come from the courier payment statement.
 *  - courier_payments (+ lines): one imported courier payment statement / settlement.
 */
return new class extends Migration {
    private function tenant(Blueprint $t): void {
        $t->id();
        $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
        $t->unique(['tenant_id', 'id']);
        $t->timestamps();
    }
    private function ref(Blueprint $t, string $col, string $parent, bool $nullable = false): void {
        $nullable ? $t->unsignedBigInteger($col)->nullable() : $t->unsignedBigInteger($col);
        $t->foreign(['tenant_id', $col], 'fk_'.substr(sha1($t->getTable().'_'.$col), 0, 20))->references(['tenant_id', 'id'])->on($parent)->restrictOnDelete();
    }
    public function up(): void {
        if (!Schema::hasTable('order_payments')) Schema::create('order_payments', function (Blueprint $t) {
            $this->tenant($t); $this->ref($t, 'sales_order_id', 'sales_orders');
            $t->string('method', 20);                 // bkash, nagad, rocket, bank, cash, card, other
            $t->bigInteger('amount_minor');
            $t->string('reference', 120)->nullable(); // trx id
            $t->timestamp('received_at');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->index(['tenant_id', 'received_at']);
        });
        if (!Schema::hasTable('courier_payments')) Schema::create('courier_payments', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('courier', 40);
            $t->string('reference', 120)->nullable();  // courier invoice / payment id
            $t->date('paid_on');
            $t->unsignedInteger('parcel_count');
            $t->bigInteger('collected_minor');
            $t->bigInteger('charge_minor');
            $t->bigInteger('net_minor');
            $t->string('note', 190)->nullable();
            $t->uuid('idempotency_key');
            $t->char('request_hash', 64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unique(['tenant_id', 'idempotency_key']);
            $t->index(['tenant_id', 'courier', 'paid_on']);
        });
        if (!Schema::hasTable('courier_payment_lines')) Schema::create('courier_payment_lines', function (Blueprint $t) {
            $this->tenant($t); $this->ref($t, 'courier_payment_id', 'courier_payments'); $this->ref($t, 'shipment_id', 'shipments');
            $t->bigInteger('expected_minor');
            $t->bigInteger('collected_minor');
            $t->bigInteger('charge_minor');
            $t->unique(['tenant_id', 'shipment_id']); // a parcel is settled once
        });
        if (!Schema::hasColumn('shipments', 'cod_expected_minor')) Schema::table('shipments', function (Blueprint $t) {
            $t->bigInteger('cod_expected_minor')->default(0);
            $t->bigInteger('cod_collected_minor')->nullable();
            $t->bigInteger('courier_charge_minor')->nullable();
            $t->timestamp('settled_at')->nullable();
        });
        // Existing parcels: expect the full order total.
        $totals = DB::table('sales_order_items')->select('tenant_id', 'sales_order_id', DB::raw('sum(quantity * unit_price_minor) as t'))->groupBy('tenant_id', 'sales_order_id');
        foreach (DB::table('shipments as s')->join('sales_orders as o', fn ($j) => $j->on('o.id', '=', 's.sales_order_id')->on('o.tenant_id', '=', 's.tenant_id'))
                     ->joinSub($totals, 'x', fn ($j) => $j->on('x.sales_order_id', '=', 's.sales_order_id')->on('x.tenant_id', '=', 's.tenant_id'))
                     ->get(['s.id', 'x.t', 'o.delivery_charge_minor', 'o.discount_minor']) as $r) {
            DB::table('shipments')->where('id', $r->id)->update(['cod_expected_minor' => max(0, (int) $r->t + (int) $r->delivery_charge_minor - (int) $r->discount_minor)]);
        }
    }
    public function down(): void {
        Schema::table('shipments', fn (Blueprint $t) => $t->dropColumn(['cod_expected_minor', 'cod_collected_minor', 'courier_charge_minor', 'settled_at']));
        Schema::dropIfExists('courier_payment_lines');
        Schema::dropIfExists('courier_payments');
        Schema::dropIfExists('order_payments');
    }
};
