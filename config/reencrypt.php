<?php

use App\Services\BankAccountService;

/*
 | Every column that uses an `encrypted` cast must be listed here, so APP_KEY rotation can re-encrypt it.
 | 'after' (optional): [class, static method] to run once the table is done (e.g. rebuild blind-index hashes).
 */
return [
    'tables' => [
        'employee_bank_accounts' => ['columns' => ['account_number'], 'after' => [BankAccountService::class, 'rehashAll']],
    ],
];
