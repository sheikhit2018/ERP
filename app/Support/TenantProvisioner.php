<?php
namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\{DB, Hash};
use Illuminate\Support\Str;

/** Creates a company (tenant), its first verified domain and its owner login. Used by the installer and CLI. */
final class TenantProvisioner {
    public static function normalizeHost(string $host): string {
        $host = strtolower(trim($host));
        $host = preg_replace('#^https?://#', '', $host);
        $host = rtrim(explode('/', $host)[0], '.');
        $host = explode(':', $host)[0];
        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $host)) $host = idn_to_ascii($host) ?: $host;
        if (!preg_match('/^[a-z0-9.-]+$/', $host)) throw new \InvalidArgumentException("Invalid hostname: $host");
        return $host;
    }

    public static function create(string $company, string $host, string $ownerName, string $ownerEmail, string $ownerPassword): int {
        $host = self::normalizeHost($host);
        return DB::transaction(function () use ($company, $host, $ownerName, $ownerEmail, $ownerPassword) {
            if (DB::table('tenant_domains')->where('hostname', $host)->exists()) throw new \RuntimeException("Domain $host is already registered.");
            $slug = Str::slug($company) ?: 'company';
            $base = $slug; $i = 1;
            while (DB::table('tenants')->where('slug', $slug)->exists()) $slug = $base.'-'.(++$i);
            $tenantId = DB::table('tenants')->insertGetId(['name' => $company, 'slug' => $slug, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('tenant_domains')->insert(['tenant_id' => $tenantId, 'hostname' => $host, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $email = strtolower(trim($ownerEmail));
            $user = User::where('email', $email)->first()
                ?? User::create(['name' => $ownerName, 'email' => $email, 'password' => Hash::make($ownerPassword)]);
            $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();
            DB::table('tenant_user')->insert(['tenant_id' => $tenantId, 'user_id' => $user->id, 'role' => 'owner', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            return $tenantId;
        });
    }

    public static function addDomain(int $tenantId, string $host): void {
        $host = self::normalizeHost($host);
        DB::table('tenant_domains')->updateOrInsert(['hostname' => $host], ['tenant_id' => $tenantId, 'verified_at' => now(), 'updated_at' => now(), 'created_at' => now()]);
    }
}
