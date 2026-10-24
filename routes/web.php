<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Web\{HrController, StoreController, AccountsController, PurchaseController, CodController, OrderImportController, SettingsController, ReportController, CourierController, CustomerController, StockInController, BomController, DashboardController, ManufacturingPageController, ProductController,
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

    // Suppliers & purchases (warehouse receives goods; paying suppliers needs the owner).
    Route::middleware('tenant.role:warehouse,manufacturer')->group(function () {
        Route::get('/purchases', [PurchaseController::class, 'index'])->name('purchases.index');
        Route::get('/purchases/create', [PurchaseController::class, 'create'])->name('purchases.create');
        Route::post('/purchases', [PurchaseController::class, 'store'])->name('purchases.store');
        Route::get('/purchases/{purchase}', [PurchaseController::class, 'show'])->whereNumber('purchase')->name('purchases.show');
        Route::post('/purchases/{purchase}/receive', [PurchaseController::class, 'receive'])->whereNumber('purchase')->name('purchases.receive');
        Route::post('/purchases/{purchase}/cancel', [PurchaseController::class, 'cancel'])->whereNumber('purchase')->name('purchases.cancel');
        Route::get('/suppliers', [PurchaseController::class, 'suppliers'])->name('suppliers.index');
        Route::post('/suppliers', [PurchaseController::class, 'saveSupplier'])->name('suppliers.save');
        Route::get('/suppliers/{supplier}', [PurchaseController::class, 'supplier'])->whereNumber('supplier')->name('suppliers.show');
    });
    Route::middleware('tenant.role:owner')->post('/suppliers/{supplier}/pay', [PurchaseController::class, 'pay'])->whereNumber('supplier')->name('suppliers.pay');
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
        Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('/customers/risk', [CustomerController::class, 'risk'])->name('customers.risk');
        Route::get('/orders', [SalesOrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{order}', [SalesOrderController::class, 'show'])->whereNumber('order')->name('orders.show');
    });
    Route::middleware('tenant.role:sales')->group(function () {
        Route::get('/reports', ReportController::class)->name('reports.index');
        Route::get('/cod', [CodController::class, 'index'])->name('cod.index');
        Route::get('/cod/import', [CodController::class, 'importPage'])->name('cod.import');
        Route::post('/cod/import', [CodController::class, 'importStore'])->name('cod.import.store');
        Route::post('/orders/{order}/payments', [CodController::class, 'payment'])->whereNumber('order')->name('orders.payments');
        Route::get('/orders/import', [OrderImportController::class, 'index'])->name('orders.import');
        Route::post('/orders/import', [OrderImportController::class, 'store'])->name('orders.import.store');
        Route::post('/orders/import/{incoming}/retry', [OrderImportController::class, 'retry'])->whereNumber('incoming')->name('orders.import.retry');
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
        Route::post('/courier/book', [CourierController::class, 'book'])->name('courier.book');
        Route::get('/courier/bookable', [CourierController::class, 'bookable'])->name('courier.bookable');
        Route::post('/courier/bookings/{booking}/cancel', [CourierController::class, 'cancelBooking'])->whereNumber('booking')->name('courier.booking.cancel');
        Route::post('/courier/sync', [CourierController::class, 'sync'])->name('courier.sync');
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

    // Locations, transfers and stock counts (warehouse); counter sales (sales + warehouse); voids & count approval (managers).
    Route::middleware('tenant.role:warehouse')->group(function () {
        Route::get('/locations', [StoreController::class, 'index'])->name('locations.index');
        Route::post('/locations', [StoreController::class, 'saveLocation'])->name('locations.save');
        Route::get('/transfers/create', [StoreController::class, 'transferForm'])->name('locations.transfer-form');
        Route::post('/transfers', [StoreController::class, 'transfer'])->name('locations.transfer');
        Route::get('/stock-counts', [StoreController::class, 'counts'])->name('counts.index');
        Route::post('/stock-counts', [StoreController::class, 'startCount'])->name('counts.start');
        Route::get('/stock-counts/{count}', [StoreController::class, 'count'])->whereNumber('count')->name('counts.show');
        Route::post('/stock-counts/{count}/lines', [StoreController::class, 'recordCount'])->whereNumber('count')->name('counts.record');
        Route::post('/stock-counts/{count}/cancel', [StoreController::class, 'cancelCount'])->whereNumber('count')->name('counts.cancel');
    });
    Route::middleware('tenant.role:sales,warehouse')->group(function () {
        Route::get('/pos', [StoreController::class, 'pos'])->name('pos.index');
        Route::post('/pos', [StoreController::class, 'sell'])->name('pos.sell');
        Route::get('/pos/{sale}/receipt', [StoreController::class, 'receipt'])->whereNumber('sale')->name('pos.receipt');
    });
    Route::middleware('tenant.role:manager')->group(function () {
        Route::post('/pos/{sale}/void', [StoreController::class, 'void'])->whereNumber('sale')->name('pos.void');
        Route::post('/stock-counts/{count}/approve', [StoreController::class, 'approveCount'])->whereNumber('count')->name('counts.approve');
    });

    // HR: attendance + production work log (factory supervisors), staff list (managers), salary money (owner).
    Route::middleware('tenant.role:manufacturer')->group(function () {
        Route::get('/hr/attendance', [HrController::class, 'attendance'])->name('hr.attendance');
        Route::post('/hr/attendance', [HrController::class, 'saveAttendance'])->name('hr.attendance.save');
        Route::get('/hr/work', [HrController::class, 'work'])->name('hr.work');
        Route::post('/hr/work', [HrController::class, 'saveWork'])->name('hr.work.save');
        Route::delete('/hr/work/{log}', [HrController::class, 'deleteWork'])->whereNumber('log')->name('hr.work.delete');
    });
    Route::middleware('tenant.role:manager')->group(function () {
        Route::get('/hr/employees', [HrController::class, 'employees'])->name('hr.employees');
        Route::post('/hr/employees', [HrController::class, 'saveEmployee'])->name('hr.employees.save');
        Route::post('/hr/stages', [HrController::class, 'saveStage'])->name('hr.stages.save');
    });

    Route::middleware('tenant.role:owner')->group(function () {
        Route::post('/hr/employees/{employee}/advance', [HrController::class, 'advance'])->whereNumber('employee')->name('hr.advance');
        Route::get('/hr/payroll', [HrController::class, 'payroll'])->name('hr.payroll');
        Route::post('/hr/payroll', [HrController::class, 'pay'])->name('hr.payroll.pay');
        Route::get('/accounts', [AccountsController::class, 'index'])->name('accounts.index');
        Route::post('/accounts/entries', [AccountsController::class, 'store'])->name('accounts.store');
        Route::post('/accounts/transfer', [AccountsController::class, 'transfer'])->name('accounts.transfer');
        Route::delete('/accounts/entries/{entry}', [AccountsController::class, 'destroy'])->whereNumber('entry')->name('accounts.destroy');
        Route::post('/accounts/accounts', [AccountsController::class, 'saveAccount'])->name('accounts.save');
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('/settings/sms-test', [SettingsController::class, 'smsTest'])->name('settings.sms-test');
        Route::post('/settings/courier-test', [SettingsController::class, 'courierTest'])->name('settings.courier-test');
        Route::get('/team', [TeamController::class, 'index'])->name('team.index');
        Route::post('/team', [TeamController::class, 'store'])->name('team.store');
        Route::put('/team/{member}', [TeamController::class, 'update'])->whereNumber('member')->name('team.update');
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

require __DIR__.'/auth.php';
