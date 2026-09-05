<?php

return [
    'statuses' => [
        'open',
        'in_progress',
        'pending',
        'completed',
        'overdue',
        'cancelled',
    ],

    'open_statuses' => [
        'open',
        'in_progress',
        'pending',
        'overdue',
    ],

    'terminal_statuses' => [
        'completed',
        'cancelled',
    ],

    /**
     * Allowed status transitions. Overdue is also applied by processOverdue.
     */
    'transitions' => [
        'open' => ['in_progress', 'pending', 'completed', 'cancelled', 'overdue'],
        'in_progress' => ['pending', 'completed', 'cancelled', 'overdue', 'open'],
        'pending' => ['open', 'in_progress', 'completed', 'cancelled', 'overdue'],
        'overdue' => ['open', 'in_progress', 'pending', 'completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ],

    'priorities' => [
        'low',
        'normal',
        'high',
        'urgent',
    ],

    'departments' => [
        'operations' => 'Operations',
        'pastoral' => 'Pastoral',
        'worship' => 'Worship',
        'children' => 'Children',
        'youth' => 'Youth',
        'finance' => 'Finance',
        'facilities' => 'Facilities',
        'communications' => 'Communications',
        'other' => 'Other',
    ],

    'attachment_constraints' => [
        'max_items' => 5,
        'max_size_bytes' => 10 * 1024 * 1024,
        'allowed_mime_types' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
            'text/plain',
        ],
        'blocked_extensions' => [
            'exe', 'bat', 'cmd', 'php', 'js', 'sh', 'dll', 'msi', 'apk',
        ],
    ],

    'overdue' => [
        'reminder_cooldown_hours' => (int) env('OPERATIONAL_TASK_REMINDER_COOLDOWN_HOURS', 24),
    ],

    'reference_prefix' => 'TASK',
];
