<?php

use App\Http\Controllers\HirePurchaseController;
use App\Http\Controllers\HirePurchaseDeductionController;
use App\Http\Controllers\HirePurchaseVendorController;
use Illuminate\Support\Facades\Route;

/*
 | Loaded by AppServiceProvider inside the same middleware as routes/web.php (web, auth, EnforceAccountPolicy).
 | Hire purchases. Literal paths come before /{hirePurchase}.
 */
Route::prefix('payroll/hire-purchases')->group(function () {
    Route::get('/', [HirePurchaseController::class, 'index'])->middleware('can:hire-purchases.view')->name('hire-purchases.index');
    Route::get('create', [HirePurchaseController::class, 'create'])->middleware('can:hire-purchases.manage')->name('hire-purchases.create');
    Route::post('/', [HirePurchaseController::class, 'store'])->middleware('can:hire-purchases.manage')->name('hire-purchases.store');
    Route::get('employees', [HirePurchaseController::class, 'employees'])->middleware('can:hire-purchases.manage')->name('hire-purchases.employees'); // employee picker of the new form

    // Vendors
    Route::get('vendors', [HirePurchaseVendorController::class, 'index'])->middleware('can:hire-purchases.view')->name('hire-purchase-vendors.index');
    Route::post('vendors', [HirePurchaseVendorController::class, 'store'])->middleware('can:hire-purchase-vendors.manage')->name('hire-purchase-vendors.store');
    Route::get('vendors/{vendor}/edit', [HirePurchaseVendorController::class, 'edit'])->middleware('can:hire-purchase-vendors.manage')->whereNumber('vendor')->name('hire-purchase-vendors.edit');
    Route::put('vendors/{vendor}', [HirePurchaseVendorController::class, 'update'])->middleware('can:hire-purchase-vendors.manage')->whereNumber('vendor')->name('hire-purchase-vendors.update');
    Route::patch('vendors/{vendor}/status', [HirePurchaseVendorController::class, 'toggle'])->middleware('can:hire-purchase-vendors.manage')->whereNumber('vendor')->name('hire-purchase-vendors.toggle');

    // Monthly deductions
    Route::get('deductions', [HirePurchaseDeductionController::class, 'index'])->middleware('can:hire-purchases.view')->name('hire-purchases.deductions');
    Route::get('deductions/export', [HirePurchaseDeductionController::class, 'export'])->middleware('can:hire-purchases.view')->name('hire-purchases.deductions.export');
    Route::post('deductions/record', [HirePurchaseDeductionController::class, 'record'])->middleware('can:hire-purchases.manage')->name('hire-purchases.deductions.record');

    // One hire purchase
    Route::get('{hirePurchase}', [HirePurchaseController::class, 'show'])->middleware('can:hire-purchases.view')->whereNumber('hirePurchase')->name('hire-purchases.show');
    Route::post('{hirePurchase}/change/preview', [HirePurchaseController::class, 'changePreview'])->middleware('can:hire-purchases.manage')->whereNumber('hirePurchase')->name('hire-purchases.change.preview');
    Route::post('{hirePurchase}/change', [HirePurchaseController::class, 'changeStore'])->middleware('can:hire-purchases.manage')->whereNumber('hirePurchase')->name('hire-purchases.change.store');
    Route::post('{hirePurchase}/stop', [HirePurchaseController::class, 'stop'])->middleware('can:hire-purchases.stop')->whereNumber('hirePurchase')->name('hire-purchases.stop');
});
