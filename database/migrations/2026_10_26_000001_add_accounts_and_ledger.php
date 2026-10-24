<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money accounts (cash, bank, bKash…) and the cash book. Every taka in or out is one ledger entry.
 * Automatic entries (COD settlements, advance payments, supplier payments, salaries, POS) carry
 * source_type/source_id so they are posted exactly once.
 */
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('money_accounts')) Schema::create('money_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->unique(['tenant_id', 'id']);
            $t->string('name', 80);
            $t->string('type', 20);                 // cash | bank | mobile
            $t->bigInteger('opening_minor')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        if (!Schema::hasTable('ledger_entries')) Schema::create('ledger_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->unique(['tenant_id', 'id']);
            $t->unsignedBigInteger('money_account_id');
            $t->foreign(['tenant_id', 'money_account_id'], 'fk_ledger_account')->references(['tenant_id', 'id'])->on('money_accounts')->restrictOnDelete();
            $t->date('entry_date');
            $t->string('direction', 3);             // in | out
            $t->bigInteger('amount_minor');
            $t->string('category', 40);
            $t->string('party_type', 20)->nullable(); // supplier | employee | courier | customer
            $t->unsignedBigInteger('party_id')->nullable();
            $t->string('reference', 120)->nullable();
            $t->string('note', 300)->nullable();
            $t->string('source_type', 30)->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->uuid('transfer_group')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'source_type', 'source_id']);
            $t->index(['tenant_id', 'money_account_id', 'entry_date']);
            $t->index(['tenant_id', 'category', 'entry_date']);
            $t->index(['tenant_id', 'party_type', 'party_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('ledger_entries'); Schema::dropIfExists('money_accounts'); }
};
