<?php
namespace App\Http\Middleware;

use App\Support\Subscription;
use Closure;
use Illuminate\Http\Request;

/** Expired plan → read-only (changes blocked, data still visible and exportable). Suspended → blocked. Super admins always pass. */
final class EnforceSubscription {
    private const ALWAYS = ['logout', 'profile*', 'two-factor*', 'export*', 'platform*', 'billing*'];
    public function handle(Request $request, Closure $next) {
        $tenant = $request->attributes->get('tenant');
        if (!$tenant || $request->user()?->is_super_admin || $request->is(...self::ALWAYS)) return $next($request);
        $s = Subscription::state((int) $tenant->id);
        if ($s['suspended']) abort(402, 'This company account is suspended. Please contact your Opryx provider.');
        if ($s['read_only'] && !in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true))
            abort(402, 'Your subscription has expired — the account is read-only. Renew to continue working.');
        return $next($request);
    }
}
