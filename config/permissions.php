<?php

/*
 | Permission catalogue: group => [permission-name => label].
 | Add new permissions here, then run: php artisan db:seed --class=AccessControlSeeder
 */
return [
    'Users' => [
        'users.view'           => 'View users',
        'users.create'         => 'Create users',
        'users.edit'           => 'Edit users & assign roles',
        'users.toggle'         => 'Activate / deactivate users',
        'users.reset-password' => 'Reset user passwords',
    ],
    'Employees' => [
        'employees.view'     => 'View employees',
        'employees.status'   => 'Activate / deactivate employees',
        'employees.fund'     => 'Set provident fund type (EPF / MSPS)',
        'employees.sync'     => 'Run MiHCM employee sync',
        'employees.sync-log' => 'View sync history',
    ],
    'Bank accounts' => [
        'bank-accounts.view'     => 'View employee bank accounts (masked)',
        'bank-accounts.reveal'   => 'Show full account numbers',
        'bank-accounts.add'      => 'Add employee bank accounts',
        'bank-accounts.activate' => 'Change the active bank account',
        'bank-accounts.remove'   => 'Remove employee bank accounts',
    ],
    'Banks & branches' => [
        'bank-master.view'   => 'View banks and branches',
        'bank-master.add'    => 'Add banks and branches',
        'bank-master.remove' => 'Remove banks and branches',
    ],
    'Pay components' => [
        'pay-components.view'     => 'View pay components and use the test panel',
        'pay-components.manage'   => 'Create and change pay components (fixed, input and system types)',
        'pay-components.formulas' => 'Create and change formula and percentage components',
    ],
    'Pay component groups' => [
        'pay-component-groups.view'   => 'View pay component groups',
        'pay-component-groups.manage' => 'Create, change and deactivate pay component groups',
    ],
    'Salary structures' => [
        'salary-structures.view'   => 'View salary structures (of your schemes)',
        'salary-structures.manage' => 'Create and change salary structures (of your schemes)',
    ],
    'Employee salary' => [
        'employee-salary.view'   => 'View employee salary tab (amounts masked)',
        'employee-salary.reveal' => 'Show salary amounts',
        'employee-salary.manage' => 'Assign structures and change employee salary components',
    ],
    'Attendance & leave' => [
        'attendance.view'     => 'View attendance (timesheets, employee Attendance tab, payroll preview)',
        'leave.view'          => 'View leave requests (employee Leave tab)',
        'attendance.load'     => 'Load attendance and leave from MiHCM',
        'attendance.load-log' => 'View load history and data-quality reports',
        'leave-types.manage'  => 'Mark leave types as paid or no-pay',
    ],
    'Schemes' => [
        'schemes.all'      => 'Access ALL schemes (no employee restriction)',
        'schemes.view'     => 'View schemes',
        'schemes.edit'     => 'Edit schemes',
        'schemes.allocate' => 'Allocate schemes to users',
    ],
    'Audit' => [
        'audit.view'   => 'View audit log',
        'audit.export' => 'Export audit log',
    ],
    'Roles' => [
        'roles.view'   => 'View roles',
        'roles.create' => 'Create roles',
        'roles.edit'   => 'Edit roles & permissions',
        'roles.toggle' => 'Activate / deactivate roles',
    ],
];
