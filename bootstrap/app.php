<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Hostinger sits behind its own proxy/CDN; trust it so HTTPS URLs are generated correctly.
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // API (integrations / future mobile app): same-site session or Sanctum token.
        $middleware->statefulApi();
        $middleware->api(prepend: [\App\Http\Middleware\ResolveTenant::class]);

        // The tenant must be known before authentication and route model binding.
        $middleware->prependToPriorityList(
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \App\Http\Middleware\ResolveTenant::class
        );

        $middleware->alias([
            'tenant' => \App\Http\Middleware\ResolveTenant::class,
            'tenant.role' => \App\Http\Middleware\RequireTenantRole::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Business-rule conflicts (409) etc. from ERP screens: go back with a readable message
        // instead of a full error page. JSON/API callers still receive the normal status code.
        $exceptions->respond(function ($response, \Throwable $e, \Illuminate\Http\Request $request) {
            if ($request->header('X-Inertia') && !$request->isMethod('GET')
                && $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                && in_array($e->getStatusCode(), [403, 404, 409, 422], true)) {
                return back()->with('error', $e->getMessage() ?: 'Action not allowed.');
            }
            return $response;
        });
    })->create();
