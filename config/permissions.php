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
    'Roles' => [
        'roles.view'   => 'View roles',
        'roles.create' => 'Create roles',
        'roles.edit'   => 'Edit roles & permissions',
        'roles.toggle' => 'Activate / deactivate roles',
    ],
];
