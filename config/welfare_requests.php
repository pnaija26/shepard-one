<?php

return [
    'request_types' => [
        'financial',
        'food',
        'housing',
        'medical',
        'pastoral',
        'other',
    ],

    'priorities' => [
        'low',
        'normal',
        'high',
        'urgent',
    ],

    'statuses' => [
        'draft',
        'submitted',
        'under_assessment',
        'returned_for_info',
        'pending_review',
        'escalated',
    ],

    'value_required_types' => [
        'financial',
        'housing',
        'medical',
    ],

    'document_constraints' => [
        'max_documents' => 5,
        'max_size_bytes' => 10 * 1024 * 1024,
        'allowed_mime_types' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
        ],
        'blocked_extensions' => [
            'exe', 'bat', 'cmd', 'php', 'js', 'sh', 'dll', 'msi', 'apk',
        ],
    ],

    'duplicate_window_days' => 30,
];
