<?php

use App\Http\Controllers\ContributionRateController;
use App\Http\Controllers\EmployeeContributionRateController;
use App\Http\Controllers\TaxTableController;
use Illuminate\Support\Facades\Route;

/*
 | Loaded by AppServiceProvider inside the same middleware as routes/web.php (web, auth, EnforceAccountPolicy).
 | Statutory: tax tables, EPF / MSPS / ETF rates, employee contribution rates.
 | The tables and default rates are global, so changing them also needs schemes.all.
 */
Route::prefix('payroll/statutory')->group(function () {
    Route::redirect('/', '/payroll/statutory/tax-tables');

    Route::get('tax-tables', [TaxTableController::class, 'index'])->middleware('can:statutory.view')->name('statutory.tax-tables');
    Route::get('tax-tables/{table:code}/versions/create', [TaxTableController::class, 'createVersion'])->middleware(['can:statutory.manage', 'can:schemes.all'])->name('statutory.tax-tables.versions.create');
    Route::post('tax-tables/{table:code}/versions', [TaxTableController::class, 'storeVersion'])->middleware(['can:statutory.manage', 'can:schemes.all'])->name('statutory.tax-tables.versions.store');
    Route::post('try', [TaxTableController::class, 'tryIt'])->middleware('can:statutory.view')->name('statutory.try');

    Route::get('rates', [ContributionRateController::class, 'index'])->middleware('can:statutory.view')->name('statutory.rates');
    Route::post('rates', [ContributionRateController::class, 'store'])->middleware(['can:statutory.manage', 'can:schemes.all'])->name('statutory.rates.store');

    Route::get('employee-rates', [EmployeeContributionRateController::class, 'index'])->middleware('can:statutory.view')->name('statutory.employee-rates.index');
    Route::get('employee-rates/{employee}', [EmployeeContributionRateController::class, 'show'])->middleware('can:statutory.view')->name('statutory.employee-rates.show');
    Route::post('employee-rates/{employee}', [EmployeeContributionRateController::class, 'store'])->middleware('can:statutory.employee-rates')->name('statutory.employee-rates.store');
});
