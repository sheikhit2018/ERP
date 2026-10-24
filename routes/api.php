<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Opryx JSON API (integrations, website order sync, future mobile app). Prefix: /api
require __DIR__.'/opryx.php';

// Signed webhooks (no login): online stores and couriers push events here. Company comes from the domain.
Route::middleware('throttle:120,1')->prefix('hooks')->group(function () {
    Route::post('woocommerce', [WebhookController::class, 'woocommerce']);
    Route::post('shopify', [WebhookController::class, 'shopify']);
    Route::post('steadfast', [WebhookController::class, 'steadfast']);
});
