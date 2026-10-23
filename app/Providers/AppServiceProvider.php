<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Installed web app (not tests / CLI): apply new migrations shipped by a deploy.
        if (!$this->app->runningInConsole() && !$this->app->runningUnitTests()
            && config('app.key') && is_file(base_path('.env'))) {
            \App\Support\AutoMigrate::runIfNeeded();
        }
    }
}
