<?php
namespace App\Support;

use App\Models\TenantSetting;

/**
 * Per-company settings (current tenant). Values are stored encrypted with APP_KEY, so API keys never sit in the
 * database in plain text. Secret keys are never sent back to the browser: the settings page only learns
 * whether a secret is set.
 */
final class Settings {
    private static array $cache = [];
    public static function get(string $key, mixed $default = null): mixed {
        $all = self::all();
        return array_key_exists($key, $all) && $all[$key] !== null && $all[$key] !== '' ? $all[$key] : $default;
    }
    public static function all(): array {
        $t = app(\App\Tenancy\TenantContext::class)->id();
        return self::$cache[$t] ??= TenantSetting::query()->get()->mapWithKeys(fn ($s) => [$s->key => $s->value])->all();
    }
    public static function put(array $values): void {
        foreach ($values as $k => $v) {
            TenantSetting::updateOrCreate(['key' => $k], ['value' => $v === null ? null : (string) $v]);
        }
        self::$cache = [];
    }
    public static function flush(): void { self::$cache = []; }
}
