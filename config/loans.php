<?php

return [
    // Every loan amount (installments, interest, settlement, fair value) is rounded to this many decimal places (mode: half_up | up | down).
    'rounding_dp'   => 2,
    'rounding_mode' => 'half_up',

    // Limits for the Excel import
    'import_max_rows' => 2000,
    'import_max_kb'   => 5120,
];
