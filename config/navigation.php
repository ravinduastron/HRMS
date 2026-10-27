<?php

/*
 | Sidebar menu: sections -> items -> optional children.
 | Optional 'can' => 'gate-ability' hides an item from users who lack it.
 | Icons are defined in resources/views/components/icon.blade.php.
 | Only screens that exist are listed. When a feature is built, add its menu item here ('live' => true).
 */
return [
    [
        'heading' => 'Overview',
        'items' => [
            ['label' => 'Dashboard', 'icon' => 'home', 'url' => '/'],
        ],
    ],
    [
        'heading' => 'Workforce',
        'items' => [
            ['label' => 'Employees', 'icon' => 'users', 'children' => [
                ['label' => 'Directory',    'url' => '/employees', 'can' => 'employees.view', 'live' => true],
                ['label' => 'Sync history', 'url' => '/employees/sync', 'can' => 'employees.sync-log', 'all_schemes' => true, 'live' => true],
            ]],
            ['label' => 'Time & Attendance', 'icon' => 'clock', 'children' => [
                ['label' => 'Timesheets', 'url' => '/attendance/timesheets', 'can' => 'attendance.view', 'live' => true],
                ['label' => 'Load data',  'url' => '/attendance/load', 'can' => 'attendance.load-log', 'all_schemes' => true, 'live' => true],
            ]],
            ['label' => 'Leave', 'icon' => 'calendar', 'children' => [
                ['label' => 'Requests',    'url' => '/leave/requests', 'can' => 'leave.view', 'live' => true],
                ['label' => 'Leave types', 'url' => '/leave/types', 'can' => 'leave-types.manage', 'live' => true],
            ]],
        ],
    ],
    [
        'heading' => 'Payroll',
        'items' => [
            ['label' => 'Compensation', 'icon' => 'document', 'children' => [
                ['label' => 'Salary structures', 'url' => '/compensation/structures', 'can' => 'salary-structures.view', 'live' => true],
                ['label' => 'Pay components',    'url' => '/payroll/components', 'can' => 'pay-components.view', 'live' => true],
                ['label' => 'Component groups',  'url' => '/payroll/groups', 'can' => 'pay-component-groups.view', 'live' => true],
            ]],
            ['label' => 'Statutory', 'icon' => 'library', 'children' => [
                ['label' => 'Tax tables',         'url' => '/payroll/statutory/tax-tables', 'can' => 'statutory.view', 'live' => true],
                ['label' => 'Contribution rates', 'url' => '/payroll/statutory/rates', 'can' => 'statutory.view', 'live' => true],
                ['label' => 'Employee rates',     'url' => '/payroll/statutory/employee-rates', 'can' => 'statutory.view', 'live' => true],
            ]],
        ],
    ],
    [
        'heading' => 'System',
        'items' => [
            ['label' => 'Administration', 'icon' => 'shield', 'children' => [
                ['label' => 'Banks & branches', 'url' => '/admin/banks', 'can' => 'bank-master.view', 'live' => true],
                ['label' => 'Schemes',          'url' => '/admin/schemes', 'can' => 'schemes.view', 'all_schemes' => true, 'live' => true],
                ['label' => 'Users',            'url' => '/admin/users', 'can' => 'users.view', 'live' => true],
                ['label' => 'Roles & access',   'url' => '/admin/roles', 'can' => 'roles.view', 'live' => true],
                ['label' => 'Audit log',        'url' => '/admin/audit', 'can' => 'audit.view', 'all_schemes' => true, 'live' => true],
            ]],
        ],
    ],
];
