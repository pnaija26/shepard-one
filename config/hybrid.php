<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Hybrid runtime (Story 12.1 / ADR-001)
    |--------------------------------------------------------------------------
    */
    'runtime' => [
        'bridge' => 'capacitor',
        'bridge_version' => '8.x',
        'ui' => 'vue',
        'app_id' => env('HYBRID_APP_ID', 'church.shepardone.app'),
        'app_name' => env('HYBRID_APP_NAME', 'ShepardOne'),
    ],

    'platforms' => [
        'android' => [
            'min_sdk' => (int) env('HYBRID_ANDROID_MIN_SDK', 24),
            'target_sdk' => (int) env('HYBRID_ANDROID_TARGET_SDK', 35),
        ],
        'ios' => [
            'min_version' => env('HYBRID_IOS_MIN_VERSION', '15.0'),
        ],
    ],

    'api' => [
        'version' => env('HYBRID_API_VERSION', '1'),
        'version_header' => 'X-API-Version',
        'require_https_outside_local' => env('HYBRID_REQUIRE_HTTPS', true),
    ],

    'device_credentials' => [
        'access_token_ttl_minutes' => (int) env('HYBRID_ACCESS_TOKEN_TTL', 60),
        'refresh_token_ttl_days' => (int) env('HYBRID_REFRESH_TOKEN_TTL_DAYS', 30),
        'max_devices_per_user' => (int) env('HYBRID_MAX_DEVICES', 10),
    ],

    /*
    | Actions that may queue while offline. Everything else fails closed.
    */
    'offline_tolerant_actions' => [
        'attendance.draft_note',
        'feedback.draft',
        'profile.draft_change',
        'notifications.mark_read',
    ],

    /*
    | Permission purposes shown before native prompts. Never request earlier.
    */
    'permissions' => [
        'camera' => [
            'purpose' => 'Scan membership or event QR codes for check-in.',
            'fallback' => 'Paste the QR payload manually.',
        ],
        'photos' => [
            'purpose' => 'Attach a photo to a welfare or care follow-up.',
            'fallback' => 'Continue without an attachment.',
        ],
        'notifications' => [
            'purpose' => 'Receive assignment, roster, and care alerts on this device.',
            'fallback' => 'Use the in-app inbox when online.',
        ],
        'microphone' => [
            'purpose' => 'Record a short voice note when typing is impractical.',
            'fallback' => 'Enter notes as text instead.',
        ],
    ],
];
