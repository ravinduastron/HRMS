<?php

return [
    'base_url'      => env('MIHCM_BASE_URL', 'https://api.mihcm.com'),
    'primary_key'   => env('MIHCM_PRIMARY_KEY'),    // Ocp-Apim-Subscription-Key + clientId
    'secondary_key' => env('MIHCM_SECONDARY_KEY'),  // clientSecret
    'verify_ssl'    => env('MIHCM_VERIFY_SSL', true),
    'timeout'       => 30,

    // Database connection for employee_sync_runs / employee_sync_changes. null = the default connection.
    // The DB user needs INSERT, SELECT and UPDATE (runs are updated while they progress) on those two tables.
    'log_connection' => env('MIHCM_LOG_CONNECTION'),
    'max_batches'   => 500,   // safety stop for the paging loop

    // A sync that would deactivate more than this % of known employees (because the API stopped
    // returning them) is NOT applied for those rows; it is flagged in the run notes instead.
    'max_missing_percent' => 50,

    // orgStructure levels used for department / section / team (same as the old module)
    'org_levels' => ['department' => 3, 'section' => 4, 'team' => 5],

    // Values never written to the sync change log (shown as masked)
    'hidden_fields' => ['nic_no'],
];
