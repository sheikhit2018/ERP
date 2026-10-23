<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Colour / size variants with barcodes.
 *  - product_styles: the design (e.g. "Polo T-Shirt"), with its own style barcode.
 *  - products: each row is now ONE sellable variant (style + colour + size) with its own barcode.
 *    Stock, orders, BOM keep working per variant exactly as before.
 *  - stock_receipts: one record per scanned "Stock In" batch (GRN), idempotent.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('product_styles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->unique(['tenant_id', 'id']);
            $t->string('code', 60);
            $t->string('name');
            $t->string('barcode', 64)->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
            $t->unique(['tenant_id', 'barcode']);
        });

        Schema::table('products', function (Blueprint $t) {
            $t->unsignedBigInteger('style_id')->nullable();
            $t->string('color', 40)->nullable();
            $t->string('size', 20)->nullable();
            $t->string('barcode', 64)->nullable();
            $t->foreign(['tenant_id', 'style_id'], 'fk_products_style')->references(['tenant_id', 'id'])->on('product_styles')->restrictOnDelete();
            $t->unique(['tenant_id', 'barcode']);
            $t->unique(['tenant_id', 'style_id', 'color', 'size'], 'products_variant_unique');
        });

        Schema::create('stock_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->unique(['tenant_id', 'id']);
            $t->uuid('idempotency_key');
            $t->char('request_hash', 64);
            $t->string('reason', 32);
            $t->string('note', 190)->nullable();
            $t->bigInteger('total_quantity');
            $t->unsignedInteger('line_count');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'idempotency_key']);
        });

        // Give every existing product a barcode so it can be scanned right away.
        foreach (DB::table('products')->whereNull('barcode')->orderBy('id')->get(['id', 'tenant_id']) as $p) {
            DB::table('products')->where('id', $p->id)->update(['barcode' => self::ean13($p->tenant_id, 50000000 + $p->id)]);
        }
    }

    /** "2" (in-store EAN range) + 3-digit tenant + 8-digit sequence + check digit. Same scheme as InventoryService. */
    public static function ean13(int $tenantId, int $seq): string {
        $body = '2'.str_pad((string) ($tenantId % 1000), 3, '0', STR_PAD_LEFT).str_pad((string) ($seq % 100000000), 8, '0', STR_PAD_LEFT);
        $sum = 0;
        foreach (str_split($body) as $i => $d) $sum += (int) $d * ($i % 2 ? 3 : 1);
        return $body.((10 - $sum % 10) % 10);
    }

    public function down(): void {
        Schema::dropIfExists('stock_receipts');
        Schema::table('products', function (Blueprint $t) {
            $t->dropForeign('fk_products_style');
            $t->dropUnique('products_variant_unique');
            $t->dropUnique(['tenant_id', 'barcode']);
            $t->dropColumn(['style_id', 'color', 'size', 'barcode']);
        });
        Schema::dropIfExists('product_styles');
    }
};
