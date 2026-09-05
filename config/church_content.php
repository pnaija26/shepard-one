<?php

return [
    'content_types' => [
        'announcement',
        'verse',
        'news',
        'sermon',
        'article',
        'testimony',
        'media',
        'download',
        'event',
    ],

    'statuses' => [
        'draft',
        'pending_approval',
        'approved',
        'published',
        'withdrawn',
        'expired',
    ],

    'visibility' => [
        'public',
        'members',
        'branch',
        'role',
    ],

    'devices' => [
        'all',
        'web',
        'mobile',
    ],

    'audiences' => [
        'all',
        'branch',
        'members',
        'role',
    ],

    'media' => [
        'allowed_mime' => [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
            'application/pdf',
            'audio/mpeg',
            'video/mp4',
        ],
        'max_bytes' => 25 * 1024 * 1024,
        'requires_alt_for' => [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
        ],
    ],

    'unsafe_markup_patterns' => [
        '/<\s*script\b/i',
        '/<\s*iframe\b/i',
        '/\bon\w+\s*=/i',
        '/javascript\s*:/i',
        '/data\s*:/i',
    ],

    'prohibited_link_patterns' => [
        '/javascript\s*:/i',
        '/data\s*:/i',
        '/^\s*file:/i',
    ],

    'search' => [
        'max_results' => 50,
    ],
];
