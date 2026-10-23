<?php
namespace App\Support;

use Illuminate\Support\Facades\Artisan;

/**
 * After a GitHub auto-deploy there is no SSH step, so the first web request after new
 * migration files arrive applies them (once, under a file lock).
 */
final class AutoMigrate {
    public static function runIfNeeded(): void {
        $dir = database_path('migrations');
        $files = glob($dir.'/*.php') ?: [];
        $sig = count($files).':'.basename((string) end($files));
        $marker = storage_path('app/migrations.sig');
        if (@file_get_contents($marker) === $sig) return;

        $lock = @fopen(storage_path('app/migrate.lock'), 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return; // another request is migrating
        try {
            if (@file_get_contents($marker) === $sig) return;
            Artisan::call('migrate', ['--force' => true]);
            @file_put_contents($marker, $sig);
        } catch (\Throwable $e) {
            report($e);
        } finally {
            flock($lock, LOCK_UN); fclose($lock);
        }
    }
}
