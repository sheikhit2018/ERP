<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{ManufacturingController, OrderController, CourierReturnController};
// Require this file from routes/api.php. ResolveTenant is prepended to the API group (see README).
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('manufacturing-orders', [ManufacturingController::class,'store'])->middleware('tenant.role:owner,manufacturer');
    Route::middleware('tenant.role:owner,sales')->group(function () {
        Route::post('orders', [OrderController::class,'store']);
        Route::post('orders/{order}/confirm', [OrderController::class,'confirm'])->whereNumber('order');
        Route::post('orders/{order}/cancel', [OrderController::class,'cancel'])->whereNumber('order');
    });
    Route::middleware('tenant.role:owner,warehouse')->group(function () {
        Route::post('orders/{order}/handover', [OrderController::class,'handover'])->whereNumber('order');
        Route::post('orders/{order}/delivered', [OrderController::class,'delivered'])->whereNumber('order');
    });
    Route::middleware('tenant.role:owner,qc')->group(function () {
        Route::post('courier-returns/scan', [CourierReturnController::class,'scan']);
        Route::post('courier-returns/{return}/qc', [CourierReturnController::class,'qc'])->whereNumber('return');
    });
});
