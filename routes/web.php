<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Web\{CourierController, StockInController, BomController, DashboardController, ManufacturingPageController, ProductController,
    RawMaterialController, ReturnPageController, SalesOrderController, StockLedgerController, TeamController};
use App\Http\Controllers\CourierReturnController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? redirect()->route('dashboard') : redirect()->route('login'));

// Every ERP screen: resolve company from the domain -> require login -> require active membership/role.
Route::middleware(['tenant', 'auth', 'tenant.role'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::middleware('tenant.role:sales,warehouse,manufacturer')->group(function () {
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/stock-ledger', StockLedgerController::class)->name('stock.ledger');
        Route::get('/scan/lookup', [StockInController::class, 'lookup'])->name('scan.lookup');
        Route::get('/labels', [ProductController::class, 'labels'])->name('products.labels');
    });
    Route::middleware('tenant.role:warehouse')->group(function () {
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::put('/products/{product}', [ProductController::class, 'update'])->whereNumber('product')->name('products.update');
        Route::post('/products/{product}/adjust', [ProductController::class, 'adjust'])->whereNumber('product')->name('products.adjust');
        Route::post('/products/{product}/barcode', [ProductController::class, 'barcode'])->whereNumber('product')->name('products.barcode');
        Route::post('/styles/{style}/variants', [ProductController::class, 'addVariants'])->whereNumber('style')->name('styles.variants');
        Route::get('/stock-in', [StockInController::class, 'index'])->name('stockin.index');
        Route::post('/stock-in', [StockInController::class, 'store'])->name('stockin.store');
    });

    Route::middleware('tenant.role:manufacturer,warehouse')->group(function () {
        Route::get('/raw-materials', [RawMaterialController::class, 'index'])->name('materials.index');
        Route::post('/raw-materials', [RawMaterialController::class, 'store'])->name('materials.store');
        Route::post('/raw-materials/{material}/adjust', [RawMaterialController::class, 'adjust'])->whereNumber('material')->name('materials.adjust');
    });
    Route::middleware('tenant.role:manufacturer')->group(function () {
        Route::get('/boms', [BomController::class, 'index'])->name('boms.index');
        Route::post('/boms', [BomController::class, 'store'])->name('boms.store');
        Route::get('/manufacturing', [ManufacturingPageController::class, 'index'])->name('manufacturing.index');
        Route::post('/manufacturing', [ManufacturingPageController::class, 'store'])->name('manufacturing.store');
    });

    Route::middleware('tenant.role:sales,warehouse')->group(function () {
        Route::get('/orders', [SalesOrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{order}', [SalesOrderController::class, 'show'])->whereNumber('order')->name('orders.show');
    });
    Route::middleware('tenant.role:sales')->group(function () {
        Route::get('/orders/create', [SalesOrderController::class, 'create'])->name('orders.create');
        Route::post('/orders', [SalesOrderController::class, 'store'])->name('orders.store');
        Route::post('/orders/{order}/confirm', [SalesOrderController::class, 'confirm'])->whereNumber('order')->name('orders.confirm');
        Route::post('/orders/{order}/cancel', [SalesOrderController::class, 'cancel'])->whereNumber('order')->name('orders.cancel');
    });
    Route::middleware('tenant.role:warehouse')->group(function () {
        Route::post('/orders/{order}/handover', [SalesOrderController::class, 'handover'])->whereNumber('order')->name('orders.handover');
        Route::post('/orders/{order}/delivered', [SalesOrderController::class, 'delivered'])->whereNumber('order')->name('orders.delivered');
    });

    // Courier hub: bulk handover, bulk returns, delivered list, lost parcels, reconciliation.
    Route::middleware('tenant.role:sales,warehouse,qc')->group(function () {
        Route::get('/courier', [CourierController::class, 'index'])->name('courier.index');
        Route::get('/courier/batches/{batch}', [CourierController::class, 'batch'])->whereNumber('batch')->name('courier.batch');
    });
    Route::middleware('tenant.role:warehouse')->group(function () {
        Route::get('/courier/pack', [CourierController::class, 'packPage'])->name('pack.index');
        Route::get('/courier/pack/order', [CourierController::class, 'packOrder'])->name('pack.order');
        Route::post('/courier/pack', [CourierController::class, 'packStore'])->name('pack.store');
        Route::get('/courier/dispatch', [CourierController::class, 'dispatchPage'])->name('courier.dispatch');
        Route::post('/courier/dispatch', [CourierController::class, 'dispatchStore'])->name('courier.dispatch.store');
        Route::get('/courier/delivered', [CourierController::class, 'deliveredPage'])->name('courier.delivered');
        Route::post('/courier/delivered', [CourierController::class, 'deliveredStore'])->name('courier.delivered.store');
        Route::post('/courier/parcels/{shipment}/lost', [CourierController::class, 'lost'])->whereNumber('shipment')->name('courier.lost');
        Route::post('/courier/parcels/{shipment}/delivered', [CourierController::class, 'deliveredOne'])->whereNumber('shipment')->name('courier.delivered.one');
    });
    Route::middleware('tenant.role:qc,warehouse')->group(function () {
        Route::get('/courier/returns', [CourierController::class, 'returnsPage'])->name('courier.returns');
        Route::post('/courier/returns', [CourierController::class, 'returnsStore'])->name('courier.returns.store');
        Route::get('/courier/parcel', [CourierController::class, 'parcel'])->name('courier.parcel');
        Route::get('/courier/parcels/by-product', [CourierController::class, 'parcelsByProduct'])->name('courier.parcels.product');
    });
    Route::middleware('tenant.role:qc,warehouse')->group(function () {
        Route::get('/returns/lookup', [StockInController::class, 'lookup'])->name('returns.lookup');
        Route::get('/returns', ReturnPageController::class)->name('returns.index');
        // JSON endpoints used by the barcode QC panel (session + CSRF protected).
        Route::post('/returns/scan', [CourierReturnController::class, 'scan'])->name('returns.scan');
        Route::post('/returns/{return}/qc', [CourierReturnController::class, 'qc'])->whereNumber('return')->name('returns.qc');
    });

    Route::middleware('tenant.role:owner')->group(function () {
        Route::get('/team', [TeamController::class, 'index'])->name('team.index');
        Route::post('/team', [TeamController::class, 'store'])->name('team.store');
        Route::put('/team/{member}', [TeamController::class, 'update'])->whereNumber('member')->name('team.update');
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

require __DIR__.'/auth.php';
