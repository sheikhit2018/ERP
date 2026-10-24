<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? $request->user()->only('id', 'name', 'email') : null,
            ],
            // Lazy: evaluated during render, inside the tenant middleware.
            'tenant' => fn () => ($t = $request->attributes->get('tenant')) ? ['name' => $t->name] : null,
            'role' => fn () => $request->attributes->get('tenant_role'),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'report' => fn () => $request->session()->get('report'),
                'error' => fn () => $request->session()->get('error'),
                'pos_sale' => fn () => $request->session()->get('pos_sale'),
            ],
            'appName' => config('app.name'),
        ];
    }
}
