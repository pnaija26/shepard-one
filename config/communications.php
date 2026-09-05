<?php

return [
    'purposes' => [
        'announcement',
        'pastoral',
        'operational',
        'engagement',
        'emergency',
        'system',
    ],

    'channels' => [
        'email' => [
            'label' => 'Email',
            'destination' => 'email',
            'allows_sensitive' => false,
        ],
        'sms' => [
            'label' => 'SMS',
            'destination' => 'phone',
            'allows_sensitive' => false,
        ],
        'push' => [
            'label' => 'Push',
            'destination' => 'user',
            'allows_sensitive' => false,
        ],
        'in_app' => [
            'label' => 'In-app',
            'destination' => 'user',
            'allows_sensitive' => true,
        ],
        'external' => [
            'label' => 'External provider',
            'destination' => 'email',
            'allows_sensitive' => false,
        ],
    ],

    'audience_types' => [
        'branch',
        'role',
        'group',
        'members',
    ],

    'schedule_types' => [
        'immediate',
        'scheduled',
        'recurring',
        'event',
        'workflow',
    ],

    'statuses' => [
        'draft',
        'queued',
        'processing',
        'completed',
        'cancelled',
        'failed',
    ],

    'delivery_statuses' => [
        'pending',
        'queued',
        'sent',
        'skipped',
        'deferred',
        'failed',
        'retried',
    ],

    'quiet_hours' => [
        'enabled' => (bool) env('COMMUNICATIONS_QUIET_HOURS', true),
        'start' => env('COMMUNICATIONS_QUIET_START', '22:00'),
        'end' => env('COMMUNICATIONS_QUIET_END', '07:00'),
        // Emergency purpose bypasses quiet hours.
        'bypass_purposes' => ['emergency'],
    ],

    'batch_size' => (int) env('COMMUNICATIONS_BATCH_SIZE', 50),
    'rate_limit_per_minute' => (int) env('COMMUNICATIONS_RATE_LIMIT', 120),
    'max_retries' => (int) env('COMMUNICATIONS_MAX_RETRIES', 3),
    'provider_quota_per_run' => (int) env('COMMUNICATIONS_PROVIDER_QUOTA', 500),

    'prohibited_patterns' => [
        '/\b(ssn|national[\s_-]?id|password)\b/i',
        '/\b\d{3}-\d{2}-\d{4}\b/', // US SSN-like
    ],

    // Preference keys under member.communication_preferences
    'preference_keys' => [
        'email' => 'email',
        'sms' => 'sms',
        'push' => 'push',
        'in_app' => 'in_app',
        'external' => 'external',
    ],
];
