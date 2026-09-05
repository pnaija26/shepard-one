<?php

return [
    'statuses' => [
        'active',
        'inactive',
    ],

    'eligible_lifecycle_statuses' => [
        'active',
    ],

    'self_service_fields' => [
        'skills',
        'expertise',
        'availability',
        'preferences',
        'certifications',
        'training',
    ],

    'coordinator_fields' => [
        'skills',
        'expertise',
        'availability',
        'preferences',
        'experience',
        'certifications',
        'training',
        'service_history',
        'volunteer_hours',
        'restricted_notes',
        'status',
    ],

    'verification_required_fields' => [
        'certifications',
        'training',
    ],

    'verification_statuses' => [
        'self_declared',
        'pending_verification',
        'verified',
        'rejected',
    ],

    'certification_expiry_warning_days' => 30,

    'skill_levels' => [
        'beginner',
        'intermediate',
        'advanced',
        'expert',
    ],
];
