<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Courier reconciliation.
 *  - courier_batches: one row per bulk handover ("dispatch") or bulk return receipt ("return"),
 *    with the parcel and piece counts the courier signed for.
 *  - shipments.dispatch_batch_id / courier_returns.return_batch_id link each parcel to its batch.
 *  - shipments.delivered_at / returned_at / lost_at close a parcel; a parcel with none of them
 *    is still with the courier. Sent − delivered − returned − lost = still with courier
 *    (overdue after the return window = suspected lost).
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('courier_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->unique(['tenant_id', 'id']);
            $t->string('type', 16);              // dispatch | return
            $t->string('courier', 40);
            $t->unsignedInteger('parcel_count');
            $t->unsignedBigInteger('piece_count');
            $t->string('note', 190)->nullable();
            $t->uuid('idempotency_key');
            $t->char('request_hash', 64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'idempotency_key']);
            $t->index(['tenant_id', 'type', 'created_at']);
            $t->index(['tenant_id', 'courier', 'created_at']);
        });

        Schema::table('shipments', function (Blueprint $t) {
            $t->unsignedBigInteger('dispatch_batch_id')->nullable();
            $t->unsignedInteger('piece_count')->default(0);
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('returned_at')->nullable();
            $t->timestamp('lost_at')->nullable();
            $t->foreign(['tenant_id', 'dispatch_batch_id'], 'fk_shipments_dispatch_batch')->references(['tenant_id', 'id'])->on('courier_batches')->restrictOnDelete();
            $t->index(['tenant_id', 'courier', 'handed_over_at']);
        });
        Schema::table('courier_returns', function (Blueprint $t) {
            $t->unsignedBigInteger('return_batch_id')->nullable();
            $t->foreign(['tenant_id', 'return_batch_id'], 'fk_courier_returns_batch')->references(['tenant_id', 'id'])->on('courier_batches')->restrictOnDelete();
        });

        // Backfill existing shipments so old parcels show correctly in the reconciliation.
        $pieces = DB::table('sales_order_items')->select('tenant_id', 'sales_order_id', DB::raw('sum(quantity) as q'))->groupBy('tenant_id', 'sales_order_id');
        foreach (DB::table('shipments')->joinSub($pieces, 'p', fn ($j) => $j->on('p.sales_order_id', '=', 'shipments.sales_order_id')->on('p.tenant_id', '=', 'shipments.tenant_id'))
                     ->get(['shipments.id', 'shipments.status', 'shipments.updated_at', 'p.q']) as $s) {
            DB::table('shipments')->where('id', $s->id)->update([
                'piece_count' => (int) $s->q,
                'delivered_at' => $s->status === 'delivered' ? $s->updated_at : null,
                'returned_at' => in_array($s->status, ['return_pending', 'returned', 'return_damaged'], true) ? $s->updated_at : null,
            ]);
        }
    }

    public function down(): void {
        Schema::table('courier_returns', function (Blueprint $t) {
            $t->dropForeign('fk_courier_returns_batch');
            $t->dropColumn('return_batch_id');
        });
        Schema::table('shipments', function (Blueprint $t) {
            $t->dropForeign('fk_shipments_dispatch_batch');
            $t->dropIndex(['tenant_id', 'courier', 'handed_over_at']);
            $t->dropColumn(['dispatch_batch_id', 'piece_count', 'delivered_at', 'returned_at', 'lost_at']);
        });
        Schema::dropIfExists('courier_batches');
    }
};
