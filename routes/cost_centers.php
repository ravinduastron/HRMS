<?php

use App\Http\Controllers\CostCenterAssignmentController;
use App\Http\Controllers\CostCenterController;
use Illuminate\Support\Facades\Route;

/*
 | Loaded by AppServiceProvider inside the same middleware as routes/web.php (web, auth, EnforceAccountPolicy).
 | Cost centers. The tree is global, so changing it also needs schemes.all. Assigning follows the scheme restrictions.
 */
Route::prefix('payroll/cost-centers')->group(function () {
    Route::get('/', [CostCenterController::class, 'index'])->middleware('can:cost-centers.view')->name('cost-centers.index');
    Route::post('/', [CostCenterController::class, 'store'])->middleware(['can:cost-centers.manage', 'can:schemes.all'])->name('cost-centers.store');

    Route::get('assign', [CostCenterAssignmentController::class, 'index'])->middleware('can:cost-centers.assign')->name('cost-centers.assign');
    Route::post('assign', [CostCenterAssignmentController::class, 'store'])->middleware('can:cost-centers.assign')->name('cost-centers.assign.store');
    Route::post('employees/{employee}', [CostCenterAssignmentController::class, 'storeForEmployee'])->middleware('can:cost-centers.assign')->name('cost-centers.employee.store');

    Route::put('{costCenter}', [CostCenterController::class, 'update'])->middleware(['can:cost-centers.manage', 'can:schemes.all'])->whereNumber('costCenter')->name('cost-centers.update');
    Route::patch('{costCenter}/status', [CostCenterController::class, 'toggle'])->middleware(['can:cost-centers.manage', 'can:schemes.all'])->whereNumber('costCenter')->name('cost-centers.toggle');
});
