<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Packing station + item-level courier returns.
 *  - sales_orders.packed_at / packed_by: every piece was scanned into the parcel (status "packed").
 *  - shipments.returned_pieces / kept_pieces / missing_pieces: what physically came back, what the
 *    customer kept (partial delivery) and what is missing (courier claim) for a returned parcel.
 *  - return_items.damaged_quantity / kept_quantity / missing_quantity: the same split per order line.
 *    return_items.quantity stays "pieces that came back" (good + damaged); a line with nothing back has no row
 *    (its kept / missing pieces are on the shipment and in inventory_movements).
 */
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasColumn('sales_orders', 'packed_at')) Schema::table('sales_orders', function (Blueprint $t) {
            $t->timestamp('packed_at')->nullable();
            $t->unsignedBigInteger('packed_by')->nullable();
            $t->foreign('packed_by')->references('id')->on('users')->restrictOnDelete();
        });
        if (!Schema::hasColumn('shipments', 'returned_pieces')) Schema::table('shipments', function (Blueprint $t) {
            $t->unsignedInteger('returned_pieces')->default(0);
            $t->unsignedInteger('kept_pieces')->default(0);
            $t->unsignedInteger('missing_pieces')->default(0);
        });
        if (!Schema::hasColumn('return_items', 'damaged_quantity')) Schema::table('return_items', function (Blueprint $t) {
            $t->bigInteger('damaged_quantity')->default(0);
            $t->bigInteger('kept_quantity')->default(0);
            $t->bigInteger('missing_quantity')->default(0);
        });
        // Whole-parcel returns recorded before this change brought every piece back.
        DB::table('shipments')->whereNotNull('returned_at')->update(['returned_pieces' => DB::raw('piece_count')]);
        DB::table('return_items')->whereIn('courier_return_id', DB::table('courier_returns')->where('status', 'damaged')->select('id'))
            ->update(['damaged_quantity' => DB::raw('quantity')]);
    }

    public function down(): void {
        Schema::table('return_items', fn (Blueprint $t) => $t->dropColumn(['damaged_quantity', 'kept_quantity', 'missing_quantity']));
        Schema::table('shipments', fn (Blueprint $t) => $t->dropColumn(['returned_pieces', 'kept_pieces', 'missing_pieces']));
        Schema::table('sales_orders', function (Blueprint $t) {
            $t->dropForeign(['packed_by']);
            $t->dropColumn(['packed_at', 'packed_by']);
        });
    }
};
