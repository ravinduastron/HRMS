<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\EnforceAccountPolicy;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:20,1')->name('login.store');
});

Route::middleware(['auth', EnforceAccountPolicy::class])->group(function () {
    Route::get('/', [HomeController::class, 'index'])->name('home');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // Own account (exempt from the forced-change redirect inside EnforceAccountPolicy)
    Route::get('/password/change', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/password/change', [PasswordController::class, 'update'])->name('password.update');
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile');

    Route::prefix('admin')->group(function () {
        // Users
        Route::get('users', [UserController::class, 'index'])->middleware('can:users.view')->name('users.index');
        Route::get('users/create', [UserController::class, 'create'])->middleware('can:users.create')->name('users.create');
        Route::post('users', [UserController::class, 'store'])->middleware('can:users.create')->name('users.store');
        Route::get('users/{user}', [UserController::class, 'show'])->middleware('can:users.view')->name('users.show');
        Route::get('users/{user}/edit', [UserController::class, 'edit'])->middleware('can:users.edit')->name('users.edit');
        Route::put('users/{user}', [UserController::class, 'update'])->middleware('can:users.edit')->name('users.update');
        Route::patch('users/{user}/status', [UserController::class, 'toggle'])->middleware('can:users.toggle')->name('users.toggle');
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->middleware('can:users.reset-password')->name('users.reset-password');

        // Audit log
        Route::get('audit', [AuditLogController::class, 'index'])->middleware('can:audit.view')->name('audit.index');
        Route::get('audit/export', [AuditLogController::class, 'export'])->middleware('can:audit.export')->name('audit.export');
        Route::get('audit/{log}', [AuditLogController::class, 'show'])->middleware('can:audit.view')->name('audit.show');

        // Roles
        Route::get('roles', [RoleController::class, 'index'])->middleware('can:roles.view')->name('roles.index');
        Route::get('roles/create', [RoleController::class, 'create'])->middleware('can:roles.create')->name('roles.create');
        Route::post('roles', [RoleController::class, 'store'])->middleware('can:roles.create')->name('roles.store');
        Route::get('roles/{role}', [RoleController::class, 'show'])->middleware('can:roles.view')->name('roles.show');
        Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->middleware('can:roles.edit')->name('roles.edit');
        Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('can:roles.edit')->name('roles.update');
        Route::patch('roles/{role}/status', [RoleController::class, 'toggle'])->middleware('can:roles.toggle')->name('roles.toggle');
    });

    // Placeholder pages for menu items not built yet (items flagged 'live' => true are skipped).
    foreach (config('navigation') as $section) {
        foreach ($section['items'] as $item) {
            foreach ($item['children'] ?? [$item] as $link) {
                if ($link['url'] !== '/' && empty($link['live'])) {
                    Route::view($link['url'], 'pages.placeholder', ['title' => $link['label']]);
                }
            }
        }
    }
});
