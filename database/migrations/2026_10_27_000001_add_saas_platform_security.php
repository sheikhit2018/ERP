<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/** SaaS: plans, subscriptions & payments, super admin; security: audit log, two-factor login. */
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasColumn('users', 'is_super_admin')) Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_super_admin')->default(false);
            $t->text('two_factor_secret')->nullable(); $t->text('two_factor_recovery_codes')->nullable(); $t->timestamp('two_factor_confirmed_at')->nullable();
            $t->string('locale', 5)->nullable();
        });
        if (!Schema::hasTable('plans')) Schema::create('plans', function (Blueprint $t) {
            $t->id(); $t->string('name', 60); $t->bigInteger('price_minor')->default(0);
            $t->unsignedInteger('users_limit')->nullable(); $t->unsignedInteger('orders_limit')->nullable(); $t->unsignedInteger('locations_limit')->nullable();
            $t->string('features', 300)->nullable(); $t->unsignedSmallInteger('sort')->default(0); $t->boolean('active')->default(true); $t->timestamps();
        });
        if (!Schema::hasColumn('tenants', 'plan_id')) Schema::table('tenants', function (Blueprint $t) {
            $t->unsignedBigInteger('plan_id')->nullable(); $t->foreign('plan_id')->references('id')->on('plans')->nullOnDelete();
            $t->string('status', 12)->default('active'); // trial | active | suspended   (null plan = unlimited / internal)
            $t->date('trial_ends_at')->nullable(); $t->date('paid_until')->nullable();
            $t->string('contact_phone', 40)->nullable(); $t->string('contact_email', 120)->nullable(); $t->string('note', 300)->nullable();
        });
        if (!Schema::hasTable('subscription_payments')) Schema::create('subscription_payments', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete(); $t->bigInteger('amount_minor'); $t->unsignedSmallInteger('months');
            $t->string('method', 20); $t->string('reference', 120)->nullable(); $t->date('paid_on'); $t->date('period_end'); $t->string('note', 200)->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
        });
        if (!Schema::hasTable('audit_logs')) Schema::create('audit_logs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tenant_id')->nullable(); $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action', 80); $t->string('method', 8)->nullable(); $t->string('path', 200)->nullable(); $t->unsignedSmallInteger('status')->nullable();
            $t->string('ip', 45)->nullable(); $t->string('agent', 160)->nullable(); $t->text('meta')->nullable(); $t->timestamp('created_at')->useCurrent();
            $t->index(['tenant_id', 'created_at']); $t->index(['user_id', 'created_at']);
        });
        // The installation owner (owner of the first company) becomes the platform super admin.
        if (!DB::table('users')->where('is_super_admin', true)->exists()) {
            $first = DB::table('tenants')->orderBy('id')->value('id');
            $uid = $first ? DB::table('tenant_user')->where('tenant_id', $first)->where('role', 'owner')->orderBy('id')->value('user_id') : null;
            if ($uid) DB::table('users')->where('id', $uid)->update(['is_super_admin' => true]);
        }
        if (!DB::table('plans')->exists()) {
            $now = now();
            DB::table('plans')->insert([
                ['name' => 'Starter', 'price_minor' => 150000, 'users_limit' => 3, 'orders_limit' => 1000, 'locations_limit' => 1, 'features' => 'Orders, courier, stock, COD, reports', 'sort' => 1, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Business', 'price_minor' => 350000, 'users_limit' => 10, 'orders_limit' => 5000, 'locations_limit' => 3, 'features' => '+ Purchases, accounts, HR & payroll, POS', 'sort' => 2, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Enterprise', 'price_minor' => 800000, 'users_limit' => null, 'orders_limit' => null, 'locations_limit' => null, 'features' => 'Unlimited users, orders and shops', 'sort' => 3, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }
    }
    public function down(): void {
        Schema::dropIfExists('audit_logs'); Schema::dropIfExists('subscription_payments');
        if (Schema::hasColumn('tenants', 'plan_id')) Schema::table('tenants', function (Blueprint $t) { $t->dropForeign(['plan_id']); $t->dropColumn(['plan_id', 'status', 'trial_ends_at', 'paid_until', 'contact_phone', 'contact_email', 'note']); });
        Schema::dropIfExists('plans');
        if (Schema::hasColumn('users', 'is_super_admin')) Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['is_super_admin', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'locale']));
    }
};
