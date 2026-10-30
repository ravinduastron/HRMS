<?php

use App\Services\BankAccountService;

/*
 | Every column that uses an `encrypted` cast must be listed here, so APP_KEY rotation can re-encrypt it.
 | 'after' (optional): [class, static method] to run once the table is done (e.g. rebuild blind-index hashes).
 */
return [
    'tables' => [
        'employee_bank_accounts' => ['columns' => ['account_number'], 'after' => [BankAccountService::class, 'rehashAll']],
        'employee_salary_lines'  => ['columns' => ['amount']],
        'loans'                  => ['columns' => ['principal']],
        'loan_installments'      => ['columns' => ['principal_amount', 'interest_amount']],
        'loan_ledger_entries'    => ['columns' => ['principal', 'interest']],
        'loan_rate_changes'      => ['columns' => ['outstanding_principal', 'old_installment', 'new_installment', 'old_remaining_interest', 'new_remaining_interest']],
        'loan_valuation_lines'   => ['columns' => ['outstanding_principal', 'nominal_remaining', 'fair_value']],
        'loan_import_rows'       => ['columns' => ['data']],
    ],
];
