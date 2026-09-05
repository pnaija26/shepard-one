<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Retention (Story 1.8 AC4)
    |--------------------------------------------------------------------------
    |
    | Records older than this window are excluded from search/export. Physical
    | archival is handled outside the application per compliance policy.
    */
    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 2555),

    /*
    |--------------------------------------------------------------------------
    | Redaction (Story 1.8 AC1)
    |--------------------------------------------------------------------------
    |
    | Keys matched case-insensitively at any depth in before/after/metadata
    | payloads are replaced with [REDACTED].
    */
    'redact_keys' => [
        'password',
        'password_confirmation',
        'token',
        'access_token',
        'refresh_token',
        'secret',
        'mfa_secret',
        'api_key',
        'authorization',
        'otp',
        'code',
        'remember_token',
    ],
];
