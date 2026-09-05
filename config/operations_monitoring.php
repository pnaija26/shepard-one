<?php

return [
    'rpo_target_minutes' => (int) env('OPS_RPO_TARGET_MINUTES', 60),

    'rto_target_minutes' => (int) env('OPS_RTO_TARGET_MINUTES', 240),

    'snapshot_retention_days' => (int) env('OPS_SNAPSHOT_RETENTION_DAYS', 30),

    'alert_dedup_window_minutes' => (int) env('OPS_ALERT_DEDUP_MINUTES', 30),

    'support_channel' => env('OPS_SUPPORT_CHANNEL', 'operations@shepardone.church'),

    'components' => [
        'application',
        'api',
        'queue',
        'scheduler',
        'search',
        'storage',
        'database',
        'integrations',
        'notifications',
        'security',
        'backups',
    ],

    'thresholds' => [
        'api_error_rate' => ['warning' => 0.02, 'critical' => 0.05],
        'api_latency_ms' => ['warning' => 1500, 'critical' => 2000],
        'queue_depth' => ['warning' => 100, 'critical' => 500],
        'failed_jobs' => ['warning' => 1, 'critical' => 5],
        'backup_age_hours' => ['warning' => 26, 'critical' => 48],
        'security_alerts' => ['warning' => 3, 'critical' => 10],
    ],

    'backup_policy' => [
        'incremental_schedule' => 'daily',
        'full_schedule' => 'weekly',
        'encryption' => 'aes-256',
        'offsite_replication' => true,
        'retention_days' => 30,
    ],

    'runbooks' => [
        'api_error_rate' => 'Review API access logs and correlation IDs, then scale workers if sustained.',
        'queue_depth' => 'Inspect failed jobs and restart queue workers.',
        'backup_failure' => 'Verify backup storage credentials and rerun the backup job.',
        'security_alerts' => 'Review security audit events and revoke suspicious credentials.',
    ],

    'redact_keys' => [
        'password',
        'secret',
        'token',
        'api_key',
        'email',
        'phone',
        'message',
        'body',
        'content',
    ],
];
