<?php

return [
    'meeting_types' => [
        'meeting',
        'activity',
    ],

    'statuses' => [
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

    'confidentiality_levels' => [
        'standard',
        'restricted',
        'pastoral',
    ],

    'action_statuses' => [
        'pending',
        'completed',
        'cancelled',
    ],

    'follow_up_triggers' => [
        'consecutive_absence',
        'visitor_present',
        'member_need',
        'overdue_action',
    ],

    'consecutive_absence_threshold' => 2,

    'default_follow_up_due_days' => 3,
];
