<?php

return [
    // Every statutory amount (APIT, EPF, MSPS, ETF) is rounded to this many decimal places with this mode (half_up | up | down).
    'rounding_dp'   => 2,
    'rounding_mode' => 'half_up',

    // The tax year (year of assessment) starts in this month: 4 = April to March.
    'tax_year_start_month' => 4,

    // A tax table whose neighbouring bands disagree by more than this many rupees at their boundary gets a warning when saved.
    'continuity_tolerance' => '1.00',
];
