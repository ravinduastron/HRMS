<?php

return [
    'enabled' => env('AUDIT_ENABLED', true),

    // null = the default DB connection. Set to a connection name (e.g. "audit") to use a separate database.
    'connection' => env('AUDIT_DB_CONNECTION'),

    // null = write immediately. Or a queue name (e.g. "audit") to write via a queue worker.
    'queue' => env('AUDIT_QUEUE'),

    // Days to keep entries (php artisan audit:prune). null = keep forever.
    'retention_days' => env('AUDIT_RETENTION_DAYS'),

    // Values of these attributes are NEVER stored (the entry only records that they changed).
    // Attributes with an encrypted/hashed cast are hidden automatically; add custom-encrypted ones per model ($auditHidden).
    'hidden' => ['password', 'password_confirmation', 'current_password', 'remember_token', 'token', 'secret', 'api_token'],

    // Attributes ignored entirely (a change to only these creates no entry).
    'exclude' => ['updated_at', 'remember_token'],

    'mask' => '[hidden]',
    'max_value_length' => 2000,

    // Key for tamper-detection checksums. Defaults to APP_KEY (rotating it invalidates audit:verify for old rows).
    'checksum_key' => env('AUDIT_CHECKSUM_KEY'),
];
