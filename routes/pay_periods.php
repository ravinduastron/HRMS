<?php

use App\Http\Controllers\PayPeriodController;
use Illuminate\Support\Facades\Route;

/*
 | Loaded by AppServiceProvider inside the same middleware as routes/web.php (web, auth, EnforceAccountPolicy).
 | Pay periods. The scheme scope limits what each user sees (out-of-scope periods are 404).
 */
Route::prefix('payroll/periods')->group(function () {
    Route::get('/', [PayPeriodController::class, 'index'])->middleware('can:pay-periods.view')->name('pay-periods.index');
    Route::post('generate', [PayPeriodController::class, 'generate'])->middleware('can:pay-periods.generate')->name('pay-periods.generate');
    Route::post('{period}/close', [PayPeriodController::class, 'close'])->middleware('can:pay-periods.close')->whereNumber('period')->name('pay-periods.close');
    Route::post('{period}/reopen', [PayPeriodController::class, 'reopen'])->middleware('can:pay-periods.reopen')->whereNumber('period')->name('pay-periods.reopen');
});
