<?php

return [
    'name'     => env('PAYROLL_SYSTEM_NAME', 'Payroll Suite'),
    'company'  => env('PAYROLL_COMPANY_NAME', 'Your Company (Pvt) Ltd'),
    // Path relative to /public, e.g. "images/logo.png" or "images/logo.svg". Empty = default mark.
    'logo'     => env('PAYROLL_LOGO'),
    'currency' => env('PAYROLL_CURRENCY', 'LKR'),
];
