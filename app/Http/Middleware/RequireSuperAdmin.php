<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
/** Platform (SaaS operator) screens. */
final class RequireSuperAdmin {
    public function handle(Request $request, Closure $next) {
        abort_unless($request->user()?->is_super_admin, 403, 'Platform admins only.');
        return $next($request);
    }
}
