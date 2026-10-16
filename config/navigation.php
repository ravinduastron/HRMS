<?php

/*
 | Sidebar menu: sections -> items -> optional children.
 | Optional 'can' => 'gate-ability' hides an item from users who lack it.
 | Icons are defined in resources/views/components/icon.blade.php.
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
                ['label' => 'Directory',   'url' => '/employees', 'can' => 'employees.view', 'live' => true],
                ['label' => 'Sync history', 'url' => '/employees/sync', 'can' => 'employees.sync-log', 'all_schemes' => true, 'live' => true],
                ['label' => 'Onboarding',  'url' => '/employees/onboarding'],
                ['label' => 'Contracts',   'url' => '/employees/contracts'],
                ['label' => 'Departments', 'url' => '/employees/departments'],
            ]],
            ['label' => 'Time & Attendance', 'icon' => 'clock', 'children' => [
                ['label' => 'Timesheets', 'url' => '/attendance/timesheets'],
                ['label' => 'Overtime',   'url' => '/attendance/overtime'],
                ['label' => 'Shifts',     'url' => '/attendance/shifts'],
                ['label' => 'Load data',  'url' => '/attendance/load', 'can' => 'attendance.load-log', 'all_schemes' => true, 'live' => true],
            ]],
            ['label' => 'Leave', 'icon' => 'calendar', 'children' => [
                ['label' => 'Requests', 'url' => '/leave/requests'],
                ['label' => 'Balances', 'url' => '/leave/balances'],
                ['label' => 'Holidays', 'url' => '/leave/holidays'],
                ['label' => 'Leave types', 'url' => '/leave/types', 'can' => 'leave-types.manage', 'live' => true],
            ]],
        ],
    ],
    [
        'heading' => 'Payroll',
        'items' => [
            ['label' => 'Pay Runs', 'icon' => 'banknotes', 'children' => [
                ['label' => 'Current run',       'url' => '/payroll/current'],
                ['label' => 'History',           'url' => '/payroll/history'],
                ['label' => 'Bonuses & arrears', 'url' => '/payroll/adjustments'],
            ]],
            ['label' => 'Compensation', 'icon' => 'document', 'children' => [
                ['label' => 'Salary structures', 'url' => '/compensation/structures', 'can' => 'salary-structures.view', 'live' => true],
                ['label' => 'Pay components',    'url' => '/payroll/components', 'can' => 'pay-components.view', 'live' => true],
                ['label' => 'Component groups',  'url' => '/payroll/groups', 'can' => 'pay-component-groups.view', 'live' => true],
                ['label' => 'Loans & advances',  'url' => '/compensation/loans'],
            ]],
            ['label' => 'Payslips', 'icon' => 'document', 'url' => '/payslips'],
        ],
    ],
    [
        'heading' => 'Compliance & insight',
        'items' => [
            ['label' => 'Statutory & Tax', 'icon' => 'library', 'children' => [
                ['label' => 'EPF / ETF',      'url' => '/statutory/epf-etf'],
                ['label' => 'Income tax',     'url' => '/statutory/income-tax'],
                ['label' => 'Bank transfers', 'url' => '/statutory/bank-files'],
            ]],
            ['label' => 'Reports', 'icon' => 'chart', 'url' => '/reports'],
        ],
    ],
    [
        'heading' => 'System',
        'items' => [
            ['label' => 'Administration', 'icon' => 'shield', 'children' => [
                ['label' => 'Banks & branches', 'url' => '/admin/banks', 'can' => 'bank-master.view', 'live' => true],
                ['label' => 'Schemes', 'url' => '/admin/schemes', 'can' => 'schemes.view', 'all_schemes' => true, 'live' => true],
                ['label' => 'Users',            'url' => '/admin/users', 'can' => 'users.view', 'live' => true],
                ['label' => 'Roles & access',   'url' => '/admin/roles', 'can' => 'roles.view', 'live' => true],
                ['label' => 'Company settings', 'url' => '/admin/company'],
                ['label' => 'Audit log',        'url' => '/admin/audit', 'can' => 'audit.view', 'all_schemes' => true, 'live' => true],
            ]],
        ],
    ],
];
