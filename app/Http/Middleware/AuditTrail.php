<?php
namespace App\Http\Middleware;

use App\Support\Audit;
use Closure;
use Illuminate\Http\Request;

/** Records every change a logged-in user makes (POST / PUT / PATCH / DELETE): who, what screen, result, IP. */
final class AuditTrail {
    private const SKIP = ['login', 'logout', 'two-factor/challenge', 'livewire', 'stock-counts/*/lines'];
    public function handle(Request $request, Closure $next) {
        $response = $next($request);
        if (!in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && $request->user() && !$request->is(...self::SKIP)) {
            $name = $request->route()?->getName() ?? $request->path();
            $ids = array_filter($request->route()?->parameters() ?? [], fn ($v) => is_scalar($v));
            $status = $response->getStatusCode();
            if ($status === 302 && $request->hasSession()) $status = $request->session()->has('errors') ? 422 : ($request->session()->has('error') ? 409 : $status);
            Audit::log($name, $ids + ['_status' => $status], (int) $request->user()->id, $request->attributes->get('tenant')->id ?? null);
        }
        return $response;
    }
}
