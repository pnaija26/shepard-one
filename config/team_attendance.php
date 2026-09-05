<?php

return [
    'occurrence_types' => [
        'duty',
        'rehearsal',
        'meeting',
    ],

    'occurrence_statuses' => [
        'scheduled',
        'completed',
        'cancelled',
    ],

    'attendance_statuses' => [
        'present',
        'absent',
        'excused',
        'late',
    ],

    'reliability_levels' => [
        'reliable',
        'moderate',
        'at_risk',
        'needs_follow_up',
    ],

    'min_records_for_analysis' => 3,

    'thresholds' => [
        'reliable_percent' => 90,
        'moderate_percent' => 75,
        'at_risk_percent' => 60,
        'follow_up_percent' => 60,
    ],
];
