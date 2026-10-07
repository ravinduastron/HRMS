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
