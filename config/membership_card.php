<?php

return [
    /*
    |--------------------------------------------------------------------------
    | QR token lifetime (seconds) — short-lived to limit replay window.
    |--------------------------------------------------------------------------
    */
    'token_ttl' => (int) env('MEMBERSHIP_CARD_TOKEN_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Replay protection — reject the same token jti within this window.
    |--------------------------------------------------------------------------
    */
    'replay_window_seconds' => (int) env('MEMBERSHIP_CARD_REPLAY_WINDOW', 120),

    'eligible_membership_statuses' => ['active'],

    'blocked_lifecycle_statuses' => [
        'inactive',
        'transferred',
        'relocated',
        'unavailable',
        'suspended',
        'deceased',
        'archived',
    ],

    'required_fields' => [
        'first_name',
        'last_name',
        'membership_id',
        'branch_id',
    ],

    /*
    |--------------------------------------------------------------------------
    | Scanner purposes and the member fields each may return.
    |--------------------------------------------------------------------------
    */
    'purposes' => [
        'identity_check' => [
            'label' => 'Identity verification',
            'fields' => ['full_name', 'membership_id', 'photo_path', 'branch', 'status'],
        ],
        'attendance' => [
            'label' => 'Service attendance',
            'fields' => ['full_name', 'membership_id', 'branch', 'status'],
        ],
        'event_admission' => [
            'label' => 'Event admission',
            'fields' => ['full_name', 'membership_id', 'status'],
        ],
    ],
];
