<?php

use App\Http\Controllers\SalaryAdjustmentController;
use Illuminate\Support\Facades\Route;

/*
 | Loaded by AppServiceProvider inside the same middleware as routes/web.php (web, auth, EnforceAccountPolicy).
 | Salary adjustments (increments etc.). Literal paths come before /{batch}. The scheme scope limits what each user sees (out-of-scope = 404).
 | Apply and discard check the permission in the controller: Excel adjustments need import, screen ones need manage.
 */
Route::prefix('compensation/adjustments')->group(function () {
    Route::get('/', [SalaryAdjustmentController::class, 'index'])->middleware('can:salary-adjustments.view')->name('salary-adjustments.index');
    Route::get('new', [SalaryAdjustmentController::class, 'create'])->middleware('can:salary-adjustments.manage')->name('salary-adjustments.create');
    Route::post('new', [SalaryAdjustmentController::class, 'store'])->middleware('can:salary-adjustments.manage')->name('salary-adjustments.store');

    Route::get('import', [SalaryAdjustmentController::class, 'importIndex'])->middleware('can:salary-adjustments.import')->name('salary-adjustments.import.index');
    Route::get('import/template', [SalaryAdjustmentController::class, 'template'])->middleware('can:salary-adjustments.import')->name('salary-adjustments.import.template');
    Route::post('import', [SalaryAdjustmentController::class, 'upload'])->middleware('can:salary-adjustments.import')->name('salary-adjustments.import.upload');

    Route::get('{batch}', [SalaryAdjustmentController::class, 'show'])->middleware('can:salary-adjustments.view')->whereNumber('batch')->name('salary-adjustments.show');
    Route::get('{batch}/reveal', [SalaryAdjustmentController::class, 'reveal'])->middleware(['can:salary-adjustments.view', 'can:employee-salary.reveal'])->whereNumber('batch')->name('salary-adjustments.reveal');
    Route::post('{batch}/apply', [SalaryAdjustmentController::class, 'apply'])->whereNumber('batch')->name('salary-adjustments.apply');
    Route::post('{batch}/discard', [SalaryAdjustmentController::class, 'discard'])->whereNumber('batch')->name('salary-adjustments.discard');
    Route::post('{batch}/rollback', [SalaryAdjustmentController::class, 'rollback'])->middleware('can:salary-adjustments.rollback')->whereNumber('batch')->name('salary-adjustments.rollback');
});
