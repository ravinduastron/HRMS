<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AttendanceLoadController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BankMasterController;
use App\Http\Controllers\EmployeeBankAccountController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeFundController;
use App\Http\Controllers\EmployeeSalaryController;
use App\Http\Controllers\EmployeeSyncController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\LeaveTypeController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PayComponentController;
use App\Http\Controllers\PayComponentGroupController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SalaryStructureController;
use App\Http\Controllers\SchemeController;
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

    // Employees (synced from MiHCM)
    Route::prefix('employees')->group(function () {
        Route::get('/', [EmployeeController::class, 'index'])->middleware('can:employees.view')->name('employees.index');
        Route::get('sync', [EmployeeSyncController::class, 'index'])->middleware(['can:employees.sync-log', 'can:schemes.all'])->name('employees.sync.index');
        Route::post('sync', [EmployeeSyncController::class, 'store'])->middleware(['can:employees.sync', 'can:schemes.all'])->name('employees.sync.store');
        Route::get('sync/{run}', [EmployeeSyncController::class, 'show'])->middleware(['can:employees.sync-log', 'can:schemes.all'])->name('employees.sync.show');
        Route::get('{employee}', [EmployeeController::class, 'show'])->middleware('can:employees.view')->name('employees.show');
        Route::post('{employee}/bank-accounts', [EmployeeBankAccountController::class, 'store'])->middleware('can:bank-accounts.add')->name('employees.bank-accounts.store');
        Route::patch('{employee}/bank-accounts/{account}/activate', [EmployeeBankAccountController::class, 'activate'])->middleware('can:bank-accounts.activate')->name('employees.bank-accounts.activate');
        Route::delete('{employee}/bank-accounts/{account}', [EmployeeBankAccountController::class, 'destroy'])->middleware('can:bank-accounts.remove')->name('employees.bank-accounts.destroy');
        Route::get('{employee}/bank-accounts/{account}/reveal', [EmployeeBankAccountController::class, 'reveal'])->middleware('can:bank-accounts.reveal')->name('employees.bank-accounts.reveal');
        Route::patch('{employee}/status', [EmployeeController::class, 'toggle'])->middleware('can:employees.status')->name('employees.status');
        Route::patch('{employee}/fund', [EmployeeFundController::class, 'update'])->middleware('can:employees.fund')->name('employees.fund');

        // Employee salary (each action adds a new immutable salary version)
        Route::get('{employee}/salary/reveal', [EmployeeSalaryController::class, 'reveal'])->middleware('can:employee-salary.reveal')->name('employees.salary.reveal');
        Route::post('{employee}/salary/assign', [EmployeeSalaryController::class, 'assign'])->middleware('can:employee-salary.manage')->name('employees.salary.assign');
        Route::patch('{employee}/salary/amounts', [EmployeeSalaryController::class, 'updateAmounts'])->middleware('can:employee-salary.manage')->name('employees.salary.amounts');
        Route::post('{employee}/salary/components', [EmployeeSalaryController::class, 'addComponent'])->middleware('can:employee-salary.manage')->name('employees.salary.components.store');
        Route::patch('{employee}/salary/components/{component}', [EmployeeSalaryController::class, 'updateAmount'])->middleware('can:employee-salary.manage')->name('employees.salary.components.amount');
        Route::delete('{employee}/salary/components/{component}', [EmployeeSalaryController::class, 'removeComponent'])->middleware('can:employee-salary.manage')->name('employees.salary.components.destroy');
    });

    // Payroll set-up: pay components + formulas
    Route::prefix('payroll/components')->group(function () {
        Route::get('/', [PayComponentController::class, 'index'])->middleware('can:pay-components.view')->name('pay-components.index');
        Route::get('create', [PayComponentController::class, 'create'])->middleware('can:pay-components.manage')->name('pay-components.create');
        Route::post('/', [PayComponentController::class, 'store'])->middleware('can:pay-components.manage')->name('pay-components.store');
        Route::post('check-formula', [PayComponentController::class, 'check'])->middleware('can:pay-components.manage')->name('pay-components.check-formula');
        Route::get('{component}', [PayComponentController::class, 'show'])->middleware('can:pay-components.view')->name('pay-components.show');
        Route::patch('{component}', [PayComponentController::class, 'update'])->middleware('can:pay-components.manage')->name('pay-components.update');
        Route::patch('{component}/status', [PayComponentController::class, 'toggle'])->middleware('can:pay-components.manage')->name('pay-components.toggle');
        Route::get('{component}/versions/create', [PayComponentController::class, 'createVersion'])->middleware('can:pay-components.manage')->name('pay-components.versions.create');
        Route::post('{component}/versions', [PayComponentController::class, 'storeVersion'])->middleware('can:pay-components.manage')->name('pay-components.versions.store');
        Route::match(['get', 'post'], '{component}/test', [PayComponentController::class, 'test'])->middleware('can:pay-components.view')->name('pay-components.test');
    });

    // Attendance and leave, read-only views (scheme restrictions apply automatically)
    Route::get('attendance/timesheets', [AttendanceController::class, 'index'])->middleware('can:attendance.view')->name('attendance.timesheets');
    Route::get('leave/requests', [LeaveRequestController::class, 'index'])->middleware('can:leave.view')->name('leave.requests');

    // Attendance & leave loads from MiHCM (manual; all-scheme administrators only, like the employee sync)
    Route::prefix('attendance/load')->group(function () {
        Route::get('/', [AttendanceLoadController::class, 'index'])->middleware(['can:attendance.load-log', 'can:schemes.all'])->name('attendance.load.index');
        Route::post('/', [AttendanceLoadController::class, 'store'])->middleware(['can:attendance.load', 'can:schemes.all'])->name('attendance.load.store');
        Route::get('{run}', [AttendanceLoadController::class, 'show'])->middleware(['can:attendance.load-log', 'can:schemes.all'])->name('attendance.load.show');
    });

    // Leave types (paid / no-pay)
    Route::prefix('leave/types')->group(function () {
        Route::get('/', [LeaveTypeController::class, 'index'])->middleware('can:leave-types.manage')->name('leave-types.index');
        Route::patch('{type}', [LeaveTypeController::class, 'update'])->middleware('can:leave-types.manage')->name('leave-types.update');
    });

    // Salary structures (templates per scheme; the scheme scope limits what each user sees)
    Route::prefix('compensation/structures')->group(function () {
        Route::get('/', [SalaryStructureController::class, 'index'])->middleware('can:salary-structures.view')->name('salary-structures.index');
        Route::get('create', [SalaryStructureController::class, 'create'])->middleware('can:salary-structures.manage')->name('salary-structures.create');
        Route::post('/', [SalaryStructureController::class, 'store'])->middleware('can:salary-structures.manage')->name('salary-structures.store');
        Route::get('{structure}', [SalaryStructureController::class, 'show'])->middleware('can:salary-structures.view')->name('salary-structures.show');
        Route::get('{structure}/edit', [SalaryStructureController::class, 'edit'])->middleware('can:salary-structures.manage')->name('salary-structures.edit');
        Route::put('{structure}', [SalaryStructureController::class, 'update'])->middleware('can:salary-structures.manage')->name('salary-structures.update');
        Route::patch('{structure}/status', [SalaryStructureController::class, 'toggle'])->middleware('can:salary-structures.manage')->name('salary-structures.toggle');
    });

    // Pay component groups (payslip grouping)
    Route::prefix('payroll/groups')->group(function () {
        Route::get('/', [PayComponentGroupController::class, 'index'])->middleware('can:pay-component-groups.view')->name('pay-component-groups.index');
        Route::post('/', [PayComponentGroupController::class, 'store'])->middleware('can:pay-component-groups.manage')->name('pay-component-groups.store');
        Route::post('quick', [PayComponentGroupController::class, 'storeQuick'])->middleware('can:pay-component-groups.manage')->name('pay-component-groups.quick-store');
        Route::get('{group}/edit', [PayComponentGroupController::class, 'edit'])->middleware('can:pay-component-groups.manage')->name('pay-component-groups.edit');
        Route::put('{group}', [PayComponentGroupController::class, 'update'])->middleware('can:pay-component-groups.manage')->name('pay-component-groups.update');
        Route::patch('{group}/status', [PayComponentGroupController::class, 'toggle'])->middleware('can:pay-component-groups.manage')->name('pay-component-groups.toggle');
    });

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

        // Banks & branches (master data: add / remove only)
        Route::get('banks', [BankMasterController::class, 'index'])->middleware('can:bank-master.view')->name('banks.index');
        Route::post('banks', [BankMasterController::class, 'storeBank'])->middleware('can:bank-master.add')->name('banks.store');
        Route::get('banks/{bank}', [BankMasterController::class, 'show'])->middleware('can:bank-master.view')->name('banks.show');
        Route::delete('banks/{bank}', [BankMasterController::class, 'destroyBank'])->middleware('can:bank-master.remove')->name('banks.destroy');
        Route::post('banks/{bank}/branches', [BankMasterController::class, 'storeBranch'])->middleware('can:bank-master.add')->name('banks.branches.store');
        Route::delete('banks/{bank}/branches/{branch}', [BankMasterController::class, 'destroyBranch'])->middleware('can:bank-master.remove')->name('banks.branches.destroy');

        // Schemes (all-scheme administrators only)
        Route::get('schemes', [SchemeController::class, 'index'])->middleware(['can:schemes.view', 'can:schemes.all'])->name('schemes.index');
        Route::get('schemes/{scheme}', [SchemeController::class, 'show'])->middleware(['can:schemes.view', 'can:schemes.all'])->name('schemes.show');
        Route::put('schemes/{scheme}', [SchemeController::class, 'update'])->middleware(['can:schemes.edit', 'can:schemes.all'])->name('schemes.update');
        Route::put('schemes/{scheme}/members', [SchemeController::class, 'members'])->middleware(['can:schemes.allocate', 'can:schemes.all'])->name('schemes.members');

        // Audit log
        Route::get('audit', [AuditLogController::class, 'index'])->middleware(['can:audit.view', 'can:schemes.all'])->name('audit.index');
        Route::get('audit/export', [AuditLogController::class, 'export'])->middleware(['can:audit.export', 'can:schemes.all'])->name('audit.export');
        Route::get('audit/{log}', [AuditLogController::class, 'show'])->middleware(['can:audit.view', 'can:schemes.all'])->name('audit.show');

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
