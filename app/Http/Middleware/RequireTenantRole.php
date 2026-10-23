<?php
namespace App\Http\Middleware;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Usage: ->middleware('tenant.role:sales,warehouse')
 * "owner" always passes; "manager" passes everything except routes marked owner-only ("owner").
 * With no arguments any active member passes.
 */
final class RequireTenantRole {
    public function handle(Request $request, Closure $next, string ...$roles) {
        abort_unless($request->user(), 401);
        $membership = DB::table('tenant_user')->where('tenant_id', app(TenantContext::class)->id())
            ->where('user_id', $request->user()->id)->where('active', true)->first();
        abort_unless($membership, 403, 'You are not a member of this company.');
        $role = $membership->role;
        $ok = $role === 'owner' || $roles === [] || in_array($role, $roles, true)
            || ($role === 'manager' && $roles !== ['owner']);
        abort_unless($ok, 403, 'Your role does not allow this action.');
        $request->attributes->set('tenant_role', $role);
        return $next($request);
    }
}
