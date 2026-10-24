<?php
namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Company settings and integrations. Secrets are stored encrypted and never sent back to the browser
 * (the page only learns whether each one is set), except the WooCommerce webhook secret that Opryx itself
 * generates and the owner must copy into WooCommerce.
 */
class SettingsController extends Controller {
    /** Secret fields per section: never echoed back. */
    public const SECRETS = ['store.shopify_secret', 'sms.api_key', 'sms.password', 'courier.steadfast.api_key', 'courier.steadfast.secret_key',
        'courier.pathao.client_secret', 'courier.pathao.password', 'courier.redx.token'];
    /** Plain fields that may be edited from the page. */
    public const FIELDS = ['orders.web_status', 'sms.enabled', 'sms.provider', 'sms.sender_id', 'sms.username', 'sms.url', 'sms.on_confirmed', 'sms.on_shipped', 'sms.on_delivered',
        'sms.tpl_confirmed', 'sms.tpl_shipped', 'sms.tpl_delivered', 'courier.steadfast.enabled', 'courier.pathao.enabled', 'courier.pathao.client_id',
        'courier.pathao.username', 'courier.pathao.store_id', 'courier.pathao.sandbox', 'courier.redx.enabled', 'courier.redx.sandbox', 'courier.redx.pickup_store_id',
        'courier.auto_sync', 'company.invoice_note', 'company.address', 'company.phone', 'company.email', 'company.locale'];

    public function index(Request $r) {
        $all = Settings::all();
        // Tokens Opryx generates and the owner copies into the store / courier panel.
        foreach (['store.woo_secret', 'courier.steadfast.webhook_token'] as $k) if (empty($all[$k])) { Settings::put([$k => Str::random(40)]); $all = Settings::all(); }
        $base = $r->getSchemeAndHttpHost();
        return Inertia::render('Settings/Index', [
            'values' => collect(self::FIELDS)->mapWithKeys(fn ($k) => [$k => $all[$k] ?? null]),
            'secrets' => collect(self::SECRETS)->mapWithKeys(fn ($k) => [$k => !empty($all[$k])]),
            'woo' => ['url' => "$base/api/hooks/woocommerce", 'secret' => $all['store.woo_secret']],
            'urls' => ['shopify' => "$base/api/hooks/shopify", 'steadfast' => "$base/api/hooks/steadfast"],
            'steadfastToken' => $all['courier.steadfast.webhook_token'],
            'smsLog' => \App\Models\SmsLog::latest('id')->take(30)->get(['id', 'sales_order_id', 'event', 'phone', 'status', 'error', 'created_at']),
        ]);
    }

    public function update(Request $r) {
        $d = $r->validate(['values' => 'array', 'values.*' => 'nullable|string|max:1000', 'secrets' => 'array', 'secrets.*' => 'nullable|string|max:500', 'regenerate_woo' => 'boolean', 'regenerate_steadfast' => 'boolean']);
        $put = [];
        foreach ($d['values'] ?? [] as $k => $v) if (in_array($k, self::FIELDS, true)) $put[$k] = $v === null ? null : trim($v);
        // Empty secret input = keep the saved one; "__clear__" removes it.
        foreach ($d['secrets'] ?? [] as $k => $v) if (in_array($k, self::SECRETS, true) && $v !== null && $v !== '') $put[$k] = $v === '__clear__' ? null : trim($v);
        if (!empty($d['regenerate_woo'])) $put['store.woo_secret'] = Str::random(40);
        if (!empty($d['regenerate_steadfast'])) $put['courier.steadfast.webhook_token'] = Str::random(40);
        Settings::put($put);
        \App\Support\Brand::forget();
        return back()->with('success', 'Settings saved.');
    }

    /** Company logo for invoices / receipts: small PNG, JPG or WebP kept inside the settings (no public file storage needed). */
    public function logo(Request $r) {
        if ($r->boolean('remove')) { Settings::put(['company.logo' => null]); return back()->with('success', 'Logo removed.'); }
        $f = $r->validate(['logo' => 'required|file|mimes:png,jpg,jpeg,webp|max:300'])['logo'];
        $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'][strtolower($f->extension())] ?? 'image/png';
        Settings::put(['company.logo' => "data:$mime;base64,".base64_encode((string) file_get_contents($f->getRealPath()))]);
        return back()->with('success', 'Logo saved.');
    }

    /** Send one test SMS with the saved gateway settings. */
    public function smsTest(Request $r) {
        $d = $r->validate(['phone' => 'required|string|max:20']);
        [$ok, $err] = \App\Services\Sms::send($d['phone'], 'Opryx test SMS from '.($r->attributes->get('tenant')->name ?? 'your company').'. Your SMS gateway works.');
        return response()->json(['ok' => $ok, 'message' => $ok ? 'Sent. Check the phone.' : "Not sent: $err"], $ok ? 200 : 422);
    }

    /** "Test connection" for a courier API with the saved credentials. */
    public function courierTest(Request $r) {
        $c = $r->validate(['courier' => 'required|in:steadfast,pathao,redx'])['courier'];
        try {
            $driver = app(\App\Services\Couriers\CourierApi::DRIVERS[$c]);
            return response()->json(['message' => $driver->test()]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
