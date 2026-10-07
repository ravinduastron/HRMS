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
        'employees.sync'     => 'Run MiHCM employee sync',
        'employees.sync-log' => 'View sync history',
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
