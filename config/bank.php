<?php

return [
    'max_accounts_per_employee' => 2,
    // false = the same bank account number cannot be registered to two different employees (ghost-employee control)
    'allow_shared_accounts' => false,
    // The full account number is hidden again this many seconds after "Show"
    'reveal_seconds' => 15,
];
