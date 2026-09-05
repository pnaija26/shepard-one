<?php

return [
    'space_types' => [
        'church',
        'branch',
        'ministry',
        'team',
        'cell',
        'department',
        'event',
    ],

    'membership_roles' => [
        'member',
        'moderator',
    ],

    'membership_statuses' => [
        'active',
        'muted',
        'banned',
        'left',
    ],

    'message_types' => [
        'text' => [
            'max_body_length' => 5000,
            'allows_attachments' => false,
        ],
        'image' => [
            'max_body_length' => 1000,
            'allows_attachments' => true,
            'allowed_mime' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
            'max_bytes' => 5 * 1024 * 1024,
        ],
        'document' => [
            'max_body_length' => 1000,
            'allows_attachments' => true,
            'allowed_mime' => [
                'application/pdf',
                'text/plain',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],
            'max_bytes' => 10 * 1024 * 1024,
        ],
        'voice_note' => [
            'max_body_length' => 500,
            'allows_attachments' => true,
            'allowed_mime' => ['audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/webm'],
            'max_bytes' => 8 * 1024 * 1024,
        ],
        'poll' => [
            'max_body_length' => 500,
            'allows_attachments' => false,
            'min_options' => 2,
            'max_options' => 8,
        ],
        'announcement' => [
            'max_body_length' => 8000,
            'allows_attachments' => false,
            'requires_moderator' => true,
        ],
    ],

    'message_statuses' => [
        'visible',
        'restricted',
        'removed',
    ],

    'moderation_actions' => [
        'pin',
        'unpin',
        'restrict',
        'remove',
        'report',
        'mute',
        'unmute',
        'ban',
        'unban',
    ],

    'default_retention_days' => (int) env('COMMUNITY_SPACE_RETENTION_DAYS', 365),
    'max_retention_days' => 1825,
    'min_retention_days' => 30,

    'search' => [
        'max_results' => 50,
    ],

    /**
     * Approved external messaging platforms only. Configuring anything else is rejected
     * so the product does not silently become a parallel chat infrastructure.
     */
    'approved_integrations' => [
        'slack' => [
            'label' => 'Slack',
            'requires_consent' => true,
            'requires_identity_mapping' => true,
            'requires_moderation_boundary' => true,
        ],
        'microsoft_teams' => [
            'label' => 'Microsoft Teams',
            'requires_consent' => true,
            'requires_identity_mapping' => true,
            'requires_moderation_boundary' => true,
        ],
    ],

    'unsafe_markup_patterns' => [
        '/<\s*script\b/i',
        '/<\s*iframe\b/i',
        '/\bon\w+\s*=/i',
        '/javascript\s*:/i',
    ],
];
