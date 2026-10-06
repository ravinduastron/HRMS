<?php

return [
    'min_length'            => 8,
    'history'               => 10,   // last N passwords cannot be reused
    'min_age_days'          => 1,    // cannot change again within N days (voluntary changes)
    'max_age_days'          => 45,   // password expires after N days
    'lockout_threshold'     => 5,    // invalid attempts before lockout
    'lockout_minutes'       => 15,   // lockout duration
    'lockout_reset_minutes' => 10,   // failed-attempt counter resets after N minutes
    'generated_length'      => 12,
];
