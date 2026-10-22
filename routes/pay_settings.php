<?php

use App\Http\Controllers\EmployeeFundController;
use App\Http\Controllers\EmployeeSalaryController;
use App\Http\Controllers\SchemePaySettingsController;
use Illuminate\Support\Facades\Route;

/*
 | Loaded by AppServiceProvider inside the same middleware as routes/web.php (web, auth, EnforceAccountPolicy).
 | Scheme pay settings, the provident-fund "follow scheme" action, and employee pay rules (hours limit, statutory exemption).
 */

// Scheme pay settings (all-scheme administrators only)
Route::prefix('admin/schemes/{scheme}/pay')->group(function () {
    Route::get('/', [SchemePaySettingsController::class, 'index'])->middleware(['can:schemes.view', 'can:schemes.all'])->name('schemes.pay.index');
    Route::post('/', [SchemePaySettingsController::class, 'store'])->middleware(['can:schemes.pay-settings', 'can:schemes.all'])->name('schemes.pay.store');
    Route::patch('fund', [SchemePaySettingsController::class, 'updateFund'])->middleware(['can:schemes.pay-settings', 'can:schemes.all'])->name('schemes.pay.fund');
});

// Employee: stop following a manual EPF/MSPS choice, and set pay rules
Route::patch('employees/{employee}/fund/follow-scheme', [EmployeeFundController::class, 'followScheme'])->middleware('can:employees.fund')->name('employees.fund.follow');
Route::patch('employees/{employee}/salary/rules', [EmployeeSalaryController::class, 'updateRules'])->middleware('can:employee-salary.manage')->name('employees.salary.rules');
