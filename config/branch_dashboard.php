<?php

return [
    'metrics' => [
        'members',
        'visitors',
        'converts',
        'attendance',
        'teams',
        'volunteers',
        'welfare',
        'care',
        'events',
        'giving',
        'growth',
        'follow_up',
    ],

    'metric_permissions' => [
        'members' => 'members.read',
        'visitors' => 'visitors.read',
        'converts' => 'members.lifecycle.read',
        'attendance' => 'attendance.read',
        'teams' => 'teams.read',
        'volunteers' => 'volunteers.read',
        'welfare' => 'welfare.reports.read',
        'care' => 'care.cases.read',
        'events' => 'events.read',
        'giving' => 'payments.giving.reports',
        'growth' => 'members.read',
        'follow_up' => 'followups.read',
    ],

    'default_period_days' => 30,

    'upcoming_event_window_days' => 30,

    'stale_thresholds' => [
        'attendance_days' => 14,
        'giving_days' => 7,
    ],

    'drill_down_limit' => 25,
];
