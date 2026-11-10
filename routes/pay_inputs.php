<?php

use App\Http\Controllers\PayInputController;
use App\Http\Controllers\PayInputImportController;
use Illuminate\Support\Facades\Route;

/*
 | Loaded by AppServiceProvider inside the same middleware as routes/web.php (web, auth, EnforceAccountPolicy).
 | Pay inputs. Literal paths come before /{input}. The scheme scope limits what each user sees (out-of-scope records are 404).
 */
Route::prefix('payroll/inputs')->group(function () {
    Route::get('/', [PayInputController::class, 'index'])->middleware('can:pay-inputs.view')->name('pay-inputs.index');
    Route::get('entry', [PayInputController::class, 'entry'])->middleware('can:pay-inputs.manage')->name('pay-inputs.entry');
    Route::post('entry', [PayInputController::class, 'entrySave'])->middleware('can:pay-inputs.manage')->name('pay-inputs.entry.save');
    Route::post('entry/employees', [PayInputController::class, 'addEmployees'])->middleware('can:pay-inputs.manage')->name('pay-inputs.entry.add');
    Route::delete('additions/{addition}', [PayInputController::class, 'removeAddition'])->middleware('can:pay-inputs.manage')->whereNumber('addition')->name('pay-inputs.additions.destroy');
    Route::get('history', [PayInputController::class, 'history'])->middleware('can:pay-inputs.view')->name('pay-inputs.history');

    // Excel upload
    Route::get('import', [PayInputImportController::class, 'index'])->middleware('can:pay-inputs.import')->name('pay-inputs.import.index');
    Route::get('import/template', [PayInputImportController::class, 'template'])->middleware('can:pay-inputs.import')->name('pay-inputs.import.template');
    Route::post('import', [PayInputImportController::class, 'upload'])->middleware('can:pay-inputs.import')->name('pay-inputs.import.upload');
    Route::get('import/{batch}', [PayInputImportController::class, 'show'])->middleware('can:pay-inputs.import')->whereNumber('batch')->name('pay-inputs.import.show');
    Route::post('import/{batch}/apply', [PayInputImportController::class, 'apply'])->middleware('can:pay-inputs.import')->whereNumber('batch')->name('pay-inputs.import.apply');
    Route::post('import/{batch}/discard', [PayInputImportController::class, 'discard'])->middleware('can:pay-inputs.import')->whereNumber('batch')->name('pay-inputs.import.discard');
    Route::post('import/{batch}/reverse', [PayInputImportController::class, 'reverse'])->middleware('can:pay-inputs.import')->whereNumber('batch')->name('pay-inputs.import.reverse');

    Route::delete('{input}', [PayInputController::class, 'destroy'])->middleware('can:pay-inputs.manage')->whereNumber('input')->name('pay-inputs.destroy');
});
