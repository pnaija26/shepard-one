<?php

return [
    'categories' => [
        'facilities' => 'Facilities',
        'sound' => 'Sound',
        'media' => 'Media',
        'ushering' => 'Ushering',
        'children' => 'Children',
        'parking' => 'Parking',
        'security' => 'Security',
        'general_experience' => 'General experience',
    ],

    'statuses' => [
        'submitted',
        'moderation_hold',
        'acknowledged',
        'in_progress',
        'reassigned',
        'closed',
        'rejected',
    ],

    'activity_types' => [
        'acknowledged',
        'action_taken',
        'reassigned',
        'closed',
        'moderation_note',
    ],

    'identity_modes' => [
        'identified',
        'anonymous',
    ],

    'category_routing' => [
        'facilities' => ['team' => 'facilities', 'label' => 'Facilities Team'],
        'sound' => ['team' => 'media', 'label' => 'Sound Team'],
        'media' => ['team' => 'media', 'label' => 'Media Team'],
        'ushering' => ['team' => 'ushering', 'label' => 'Ushering Team'],
        'children' => ['team' => 'children', 'label' => 'Children Ministry'],
        'parking' => ['team' => 'operations', 'label' => 'Operations Team'],
        'security' => ['team' => 'security', 'label' => 'Security Team'],
        'general_experience' => ['team' => 'pastoral', 'label' => 'Pastoral Care'],
    ],

    'identity_policy' => [
        'default_mode' => 'identified',
        'allow_anonymous' => true,
    ],

    'moderation' => [
        'prohibited_keywords' => [
            'spam',
            'scam',
            'viagra',
            'casino',
        ],
        'hold_on_match' => true,
    ],

    'notifications' => [
        'notify_on_close' => true,
        'require_consent' => true,
    ],

    'gathering_models' => [
        'church_service' => \App\Models\ChurchService::class,
        'church_event' => \App\Models\ChurchEvent::class,
    ],
];
