<?php
namespace App\Http\Middleware;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ResolveTenant {
    public function handle(Request $request, Closure $next) {
        $context = app(TenantContext::class);
        $context->clear();
        try {
            // Explicit domain registry, including subdomains. Never trust a request-supplied tenant_id.
            $host = strtolower(rtrim($request->getHost(), '.'));
            $tenant = DB::table('tenant_domains as d')->join('tenants as t', 't.id', '=', 'd.tenant_id')
                ->where('d.hostname', $host)->whereNotNull('d.verified_at')->where('t.active', true)
                ->select('t.id', 't.name')->first();
            abort_unless($tenant, 404, 'Unknown or inactive company domain.');
            $context->set((int)$tenant->id);
            $request->attributes->set('tenant', $tenant);
            return $next($request);
        } finally { $context->clear(); }
    }
}
