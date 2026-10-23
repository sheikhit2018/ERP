<?php
namespace App\Providers;
use App\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;
class OpryxServiceProvider extends ServiceProvider {
    public function register(): void { $this->app->scoped(TenantContext::class, fn () => new TenantContext); }
}
