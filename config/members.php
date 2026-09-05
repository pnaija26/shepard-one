<?php

return [
    'membership_id_prefix' => env('MEMBER_ID_PREFIX', 'S1-M-'),

    'registration_channels' => [
        'web',
        'mobile',
        'reception',
        'branch_admin',
        'qr',
        'kiosk',
        'import',
    ],

    'statuses' => [
        'active',
        'inactive',
        'transferred',
        'relocated',
        'unavailable',
        'suspended',
        'deceased',
        'archived',
    ],

    'lifecycle' => [
        'stages' => ['visitor', 'convert', 'member'],

        'stage_rules' => [
            ['from' => 'visitor', 'to' => 'convert', 'requires' => ['reason']],
            ['from' => 'visitor', 'to' => 'member', 'requires' => ['reason', 'milestone'], 'requires_approval' => true],
            ['from' => 'convert', 'to' => 'member', 'requires' => ['reason', 'milestone']],
        ],

        'status_rules' => [
            ['to' => 'inactive', 'requires' => ['reason']],
            ['to' => 'transferred', 'requires' => ['reason', 'evidence'], 'requires_approval' => true],
            ['to' => 'relocated', 'requires' => ['reason']],
            ['to' => 'unavailable', 'requires' => ['reason']],
            ['to' => 'suspended', 'requires' => ['reason', 'evidence']],
            ['to' => 'deceased', 'requires' => ['reason', 'evidence'], 'requires_approval' => true],
            ['to' => 'archived', 'requires' => ['reason']],
            ['to' => 'active', 'requires' => ['reason']],
        ],

        'status_policies' => [
            'active' => ['communications' => 'enabled', 'permissions' => 'full'],
            'inactive' => ['communications' => 'limited', 'permissions' => 'read_only'],
            'transferred' => ['communications' => 'limited', 'permissions' => 'read_only'],
            'relocated' => ['communications' => 'limited', 'permissions' => 'read_only'],
            'unavailable' => ['communications' => 'limited', 'permissions' => 'read_only'],
            'suspended' => ['communications' => 'none', 'permissions' => 'revoked'],
            'deceased' => ['communications' => 'none', 'permissions' => 'revoked'],
            'archived' => ['communications' => 'none', 'permissions' => 'revoked'],
        ],
    ],

    'writable_fields' => [
        'basic' => [
            'first_name',
            'last_name',
            'preferred_name',
            'email',
            'phone',
            'date_of_birth',
            'gender',
            'address_line1',
            'address_line2',
            'city',
            'state',
            'postal_code',
            'country',
            'occupation',
            'photo_path',
            'emergency_contact',
            'consent_data_processing',
            'consent_directory',
        ],
        'preferences' => [
            'spiritual_gifts',
            'skills',
            'ministry_interests',
            'communication_preferences',
        ],
    ],

    'duplicate_rules' => [
        ['field' => 'email', 'confidence' => 'high'],
        ['field' => 'phone', 'confidence' => 'high'],
        ['fields' => ['first_name', 'last_name', 'date_of_birth'], 'confidence' => 'medium'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Self-service profile policy (Story 2.2)
    |--------------------------------------------------------------------------
    */
    'self_service' => [
        'immediate' => [
            'phone',
            'address_line1',
            'address_line2',
            'city',
            'state',
            'postal_code',
            'country',
            'occupation',
            'emergency_contact',
        ],
        'approval_required' => [
            'email',
            'photo_path',
            'preferred_name',
        ],
        'forbidden' => [
            'first_name',
            'last_name',
            'date_of_birth',
            'gender',
            'membership_status',
            'membership_id',
            'branch_id',
            'registration_channel',
            'consent_data_processing',
            'consent_directory',
            'spiritual_gifts',
            'skills',
            'ministry_interests',
            'communication_preferences',
            'restricted_summaries',
            'archived_at',
        ],
    ],
];
