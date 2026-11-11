<?php

use App\Http\Controllers\CompanyBankAccountController;
use App\Http\Controllers\CompanyController;
use Illuminate\Support\Facades\Route;

/*
 | Loaded by AppServiceProvider inside the same middleware as routes/web.php (web, auth, EnforceAccountPolicy).
 | Company profile and paying bank accounts. The company is global, so changes also need schemes.all.
 */
Route::prefix('admin/company')->group(function () {
    Route::get('/', [CompanyController::class, 'index'])->middleware('can:company.view')->name('company.index');
    Route::put('/', [CompanyController::class, 'update'])->middleware(['can:company.manage', 'can:schemes.all'])->name('company.update');

    $manage = ['can:company-bank-accounts.manage', 'can:schemes.all'];
    Route::post('accounts', [CompanyBankAccountController::class, 'store'])->middleware($manage)->name('company.accounts.store');
    Route::patch('accounts/{account}/default', [CompanyBankAccountController::class, 'makeDefault'])->middleware($manage)->whereNumber('account')->name('company.accounts.default');
    Route::patch('accounts/{account}/status', [CompanyBankAccountController::class, 'toggle'])->middleware($manage)->whereNumber('account')->name('company.accounts.toggle');
    Route::delete('accounts/{account}', [CompanyBankAccountController::class, 'destroy'])->middleware($manage)->whereNumber('account')->name('company.accounts.destroy');
    Route::get('accounts/{account}/reveal', [CompanyBankAccountController::class, 'reveal'])->middleware(['can:company-bank-accounts.reveal'])->whereNumber('account')->name('company.accounts.reveal');
});
