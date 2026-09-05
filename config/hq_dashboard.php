<?php

return [
    'metrics' => [
        'members',
        'growth',
        'attendance',
        'visitors',
        'converts',
        'baptisms',
        'teams',
        'volunteers',
        'welfare',
        'care',
        'events',
        'giving',
        'follow_up',
        'branch_performance',
    ],

    'metric_permissions' => [
        'members' => 'members.read',
        'growth' => 'members.read',
        'attendance' => 'attendance.read',
        'visitors' => 'visitors.read',
        'converts' => 'members.lifecycle.read',
        'baptisms' => 'members.lifecycle.read',
        'teams' => 'teams.read',
        'volunteers' => 'volunteers.read',
        'welfare' => 'welfare.reports.read',
        'care' => 'care.cases.read',
        'events' => 'events.read',
        'giving' => 'payments.giving.reports',
        'follow_up' => 'followups.read',
        'branch_performance' => 'organizations.read',
    ],

    'metric_definitions' => [
        'members' => 'Active members with lifecycle status active, excluding merged records.',
        'growth' => 'New member registrations in the selected period.',
        'attendance' => 'Attendance records captured in the selected period.',
        'visitors' => 'Visitors with first visit (or capture date) in the selected period.',
        'converts' => 'Members currently in the convert lifecycle stage.',
        'baptisms' => 'Recorded baptism milestones in the selected period.',
        'teams' => 'Active service teams and active team assignments.',
        'volunteers' => 'Active volunteer profiles and participation rate among members.',
        'welfare' => 'Open welfare cases and submissions in the selected period.',
        'care' => 'Open pastoral care cases visible to the viewer within scope.',
        'events' => 'Published upcoming events within the configured window.',
        'giving' => 'Reconciled successful contributions in the selected period (donor identity minimized).',
        'follow_up' => 'Open follow-up assignments and overdue items.',
        'branch_performance' => 'Branch-level comparison using the same metric definitions with disclosure controls.',
    ],

    'default_period_days' => 30,

    'upcoming_event_window_days' => 30,

    'stale_thresholds' => [
        'attendance_days' => 14,
        'giving_days' => 7,
    ],

    'drill_down_limit' => 25,

    'disclosure' => [
        'min_cohort_size' => 5,
        'sensitive_metrics' => ['care', 'welfare', 'giving'],
    ],

    'branch_comparison_metrics' => [
        'members',
        'growth',
        'attendance',
        'giving',
        'care',
        'welfare',
    ],
];
