<?php

return [
    'sources' => [
        'service',
        'event',
        'outreach',
        'referral',
        'online',
        'other',
    ],

    'attendance_statuses' => [
        'first_timer',
        'returning',
        'visitor',
    ],

    'decision_types' => [
        'salvation',
        'rededication',
        'membership_interest',
        'baptism_interest',
        'counselling_request',
        'other',
    ],

    'duplicate_rules' => [
        ['field' => 'email', 'confidence' => 'high'],
        ['field' => 'phone', 'confidence' => 'high'],
        ['fields' => ['first_name', 'last_name', 'phone'], 'confidence' => 'medium'],
    ],
];
