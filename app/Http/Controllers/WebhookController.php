<?php
namespace App\Http\Controllers;

use App\Services\OrderImportService;
use App\Support\{Settings, SystemActor};
use Illuminate\Http\Request;

/**
 * Online-store webhooks (company resolved from the domain). Each request is verified with the shared secret
 * saved in Settings → Online store; unsigned or wrongly signed requests are rejected.
 */
class WebhookController extends Controller {
    private static function valid(Request $r, string $secret, string $header): bool {
        $sig = (string) $r->header($header, '');
        return $secret !== '' && $sig !== '' && hash_equals(base64_encode(hash_hmac('sha256', $r->getContent(), $secret, true)), $sig);
    }

    public function woocommerce(Request $r, OrderImportService $s) {
        $secret = (string) Settings::get('store.woo_secret', '');
        abort_if($secret === '', 404);
        // WooCommerce sends "webhook_id=…" (unsigned form post) once when the webhook is saved.
        if (!$r->isJson() && $r->has('webhook_id')) return response()->json(['ok' => true]);
        abort_unless(self::valid($r, $secret, 'X-WC-Webhook-Signature'), 401, 'Bad signature.');
        $payload = $r->json()->all();
        $n = OrderImportService::fromWoo($payload);
        if (!$n || in_array($payload['status'] ?? '', ['cancelled', 'failed', 'refunded', 'trash', 'checkout-draft'], true)) return response()->json(['ok' => true, 'skipped' => true]);
        $in = $s->receive('woocommerce', $n, $payload, SystemActor::id());
        return response()->json(['ok' => true, 'status' => $in->status, 'order_id' => $in->sales_order_id]);
    }

    public function shopify(Request $r, OrderImportService $s) {
        $secret = (string) Settings::get('store.shopify_secret', '');
        abort_if($secret === '', 404);
        abort_unless(self::valid($r, $secret, 'X-Shopify-Hmac-Sha256'), 401, 'Bad signature.');
        $payload = $r->json()->all();
        $n = OrderImportService::fromShopify($payload);
        if (!$n || !empty($payload['cancelled_at'])) return response()->json(['ok' => true, 'skipped' => true]);
        $in = $s->receive('shopify', $n, $payload, SystemActor::id());
        return response()->json(['ok' => true, 'status' => $in->status, 'order_id' => $in->sales_order_id]);
    }

    /**
     * Steadfast status callback: Authorization: Bearer <token from Settings → Courier API>.
     * Body: consignment_id, invoice, status, cod_amount, updated_at (delivery_status notifications).
     */
    public function steadfast(Request $r, \App\Services\Couriers\CourierApi $api) {
        $token = (string) Settings::get('courier.steadfast.webhook_token', '');
        abort_if($token === '', 404);
        abort_unless(hash_equals('Bearer '.$token, (string) $r->header('Authorization', '')), 401, 'Unauthorized');
        $cid = (string) ($r->input('consignment_id') ?? '');
        $status = (string) ($r->input('status') ?? $r->input('delivery_status') ?? '');
        if ($cid === '' || $status === '') return response()->json(['status' => 'ignored']);
        $s = \App\Services\Couriers\CourierApi::shipmentFor('steadfast', $cid);
        if ($s) { $api->apply($s, \App\Services\Couriers\Steadfast::state($status), $status, SystemActor::id()); return response()->json(['status' => 'success']); }
        // Not handed over yet: remember the status on the booking (e.g. cancelled before pickup).
        \App\Models\CourierBooking::where('courier', 'steadfast')->where('consignment_id', $cid)->update(['courier_status' => mb_substr($status, 0, 60)]);
        return response()->json(['status' => 'success']);
    }
}
