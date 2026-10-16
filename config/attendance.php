<?php

return [
    // Database connection for attendance, leave and the load history. null = the default (main) connection.
    // Set ATTENDANCE_DB_CONNECTION=attendance to use the separate database defined in config/database.php.
    'connection' => env('ATTENDANCE_DB_CONNECTION'),

    'max_days'    => 100,   // longest date range a single load may cover
    'max_batches' => 500,   // safety stop for the attendance paging loop

    // A load that would flag more than this % of the rows already stored in its range as "missing from MiHCM"
    // (and more than 5 rows) flags none of them; the run notes say so instead.
    'max_missing_percent' => 50,

    // Which attendance field holds the employee number that matches employees.employee_no: 'number' or 'textCardNumber'.
    'employee_no_field' => env('ATTENDANCE_EMPLOYEE_NO_FIELD', 'number'),
    // If the attendance feed sends numbers without leading zeros (100) but employees use 00100, set the length (5). Empty = no padding.
    'employee_no_pad' => env('ATTENDANCE_EMPLOYEE_NO_PAD') ?: null,

    // Which approved-overtime value payroll will use: 'decimal' (overtimeApproved) or 'text' (overtimeApprovedString).
    // Both are stored. MiHCM's sample data disagrees between them, so confirm with MiHCM before relying on it.
    'ot_approved_source' => env('ATTENDANCE_OT_APPROVED_SOURCE', 'decimal'),
];
