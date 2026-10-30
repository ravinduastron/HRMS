<?php

use App\Http\Controllers\LoanController;
use App\Http\Controllers\LoanDeductionController;
use App\Http\Controllers\LoanImportController;
use App\Http\Controllers\LoanTypeController;
use App\Http\Controllers\LoanValuationController;
use Illuminate\Support\Facades\Route;

/*
 | Loaded by AppServiceProvider inside the same middleware as routes/web.php (web, auth, EnforceAccountPolicy).
 | Loans and advances. Literal paths come before /{loan}.
 */
Route::prefix('payroll/loans')->group(function () {
    Route::get('/', [LoanController::class, 'index'])->middleware('can:loans.view')->name('loans.index');
    Route::get('create', [LoanController::class, 'create'])->middleware('can:loans.manage')->name('loans.create');
    Route::post('/', [LoanController::class, 'store'])->middleware('can:loans.manage')->name('loans.store');
    Route::post('preview', [LoanController::class, 'preview'])->middleware('can:loans.manage')->name('loans.preview');

    // Loan types
    Route::get('types', [LoanTypeController::class, 'index'])->middleware('can:loans.view')->name('loan-types.index');
    Route::get('types/create', [LoanTypeController::class, 'create'])->middleware('can:loan-types.manage')->name('loan-types.create');
    Route::post('types', [LoanTypeController::class, 'store'])->middleware('can:loan-types.manage')->name('loan-types.store');
    Route::get('types/{type}/edit', [LoanTypeController::class, 'edit'])->middleware('can:loan-types.manage')->name('loan-types.edit');
    Route::put('types/{type}', [LoanTypeController::class, 'update'])->middleware('can:loan-types.manage')->name('loan-types.update');
    Route::patch('types/{type}/status', [LoanTypeController::class, 'toggle'])->middleware('can:loan-types.manage')->name('loan-types.toggle');

    // Monthly deductions: capital and interest apart
    Route::get('deductions', [LoanDeductionController::class, 'index'])->middleware('can:loans.view')->name('loans.deductions');
    Route::get('deductions/export', [LoanDeductionController::class, 'export'])->middleware('can:loans.view')->name('loans.deductions.export');
    Route::post('deductions/record', [LoanDeductionController::class, 'record'])->middleware('can:loans.manage')->name('loans.deductions.record');

    // Excel import of existing loans
    Route::get('import', [LoanImportController::class, 'index'])->middleware('can:loans.import')->name('loans.import.index');
    Route::get('import/template', [LoanImportController::class, 'template'])->middleware('can:loans.import')->name('loans.import.template');
    Route::post('import', [LoanImportController::class, 'upload'])->middleware('can:loans.import')->name('loans.import.upload');
    Route::get('import/{batch}', [LoanImportController::class, 'show'])->middleware('can:loans.import')->whereNumber('batch')->name('loans.import.show');
    Route::post('import/{batch}/apply', [LoanImportController::class, 'apply'])->middleware('can:loans.import')->whereNumber('batch')->name('loans.import.apply');
    Route::post('import/{batch}/discard', [LoanImportController::class, 'discard'])->middleware('can:loans.import')->whereNumber('batch')->name('loans.import.discard');
    Route::post('import/{batch}/reverse', [LoanImportController::class, 'reverse'])->middleware('can:loans.import')->whereNumber('batch')->name('loans.import.reverse');

    // Fair value
    Route::get('fair-value', [LoanValuationController::class, 'index'])->middleware('can:loans.valuation')->name('loans.fair-value');
    Route::get('fair-value/export', [LoanValuationController::class, 'export'])->middleware('can:loans.valuation')->name('loans.fair-value.export');
    Route::post('fair-value/market-rates', [LoanValuationController::class, 'storeMarketRate'])->middleware(['can:loans.valuation', 'can:schemes.all'])->name('loans.fair-value.market-rates');
    Route::post('fair-value/snapshots', [LoanValuationController::class, 'saveSnapshot'])->middleware(['can:loans.valuation', 'can:schemes.all'])->name('loans.fair-value.save');
    Route::get('fair-value/snapshots/{valuation}', [LoanValuationController::class, 'snapshot'])->middleware('can:loans.valuation')->whereNumber('valuation')->name('loans.fair-value.snapshot');
    Route::get('fair-value/snapshots/{valuation}/export', [LoanValuationController::class, 'snapshotExport'])->middleware('can:loans.valuation')->whereNumber('valuation')->name('loans.fair-value.snapshot.export');

    // One loan
    Route::get('{loan}', [LoanController::class, 'show'])->middleware('can:loans.view')->whereNumber('loan')->name('loans.show');
    Route::post('{loan}/rate/preview', [LoanController::class, 'ratePreview'])->middleware('can:loans.manage')->whereNumber('loan')->name('loans.rate.preview');
    Route::post('{loan}/rate', [LoanController::class, 'rateStore'])->middleware('can:loans.manage')->whereNumber('loan')->name('loans.rate.store');
    Route::get('{loan}/settle', [LoanController::class, 'settle'])->middleware('can:loans.settle')->whereNumber('loan')->name('loans.settle');
    Route::post('{loan}/settle', [LoanController::class, 'settleStore'])->middleware('can:loans.settle')->whereNumber('loan')->name('loans.settle.store');
    Route::post('{loan}/cancel', [LoanController::class, 'cancel'])->middleware('can:loans.manage')->whereNumber('loan')->name('loans.cancel');
});
