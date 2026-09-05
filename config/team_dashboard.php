<?php

return [
    'widgets' => [
        'membership',
        'availability',
        'attendance',
        'services',
        'rosters',
        'assignments',
        'reports',
        'tasks',
        'follow_ups',
        'new_members',
        'training',
        'events',
        'notifications',
        'indicators',
        'issues',
    ],

    'widget_states' => [
        'ready',
        'empty',
        'stale',
        'unauthorized',
    ],

    'stale_thresholds' => [
        'attendance_days' => 14,
        'reports_days' => 7,
    ],

    'upcoming_window_days' => 14,

    'new_member_window_days' => 30,

    'urgency_labels' => [
        'critical' => 'Critical — act today',
        'high' => 'High priority',
        'normal' => 'Needs attention',
    ],

    'priority_action_types' => [
        'overdue_follow_up',
        'uncaptured_attendance',
        'roster_response',
        'report_draft',
        'report_returned',
        'pending_assignment',
        'open_incident',
        'new_member_welcome',
    ],

    'drill_down_limits' => [
        'default' => 25,
        'notifications' => 50,
    ],

    'next_actions' => [
        'membership' => [
            ['action' => 'view_assignments', 'label' => 'Review assignments', 'path' => '/service-teams', 'permission' => 'teams.assignments.read'],
        ],
        'attendance' => [
            ['action' => 'capture_attendance', 'label' => 'Capture attendance', 'path' => '/team-attendance', 'permission' => 'teams.attendance.capture'],
        ],
        'rosters' => [
            ['action' => 'manage_rosters', 'label' => 'Open rosters', 'path' => '/team-rosters', 'permission' => 'teams.rosters.manage'],
        ],
        'assignments' => [
            ['action' => 'assign_members', 'label' => 'Assign members', 'path' => '/service-teams', 'permission' => 'teams.assignments.manage'],
        ],
        'reports' => [
            ['action' => 'submit_report', 'label' => 'Submit report', 'path' => '/team-reports', 'permission' => 'teams.reports.submit'],
            ['action' => 'review_report', 'label' => 'Review report', 'path' => '/team-reports', 'permission' => 'teams.reports.review'],
        ],
        'tasks' => [
            ['action' => 'open_follow_ups', 'label' => 'Open follow-ups', 'path' => '/follow-ups', 'permission' => 'followups.work'],
        ],
        'training' => [
            ['action' => 'review_volunteers', 'label' => 'Review volunteer profiles', 'path' => '/volunteers', 'permission' => 'volunteers.manage'],
        ],
        'events' => [
            ['action' => 'view_events', 'label' => 'View events', 'path' => '/events', 'permission' => 'events.read'],
        ],
        'notifications' => [],
        'issues' => [
            ['action' => 'respond_incidents', 'label' => 'Respond to incidents', 'path' => '/incidents', 'permission' => 'incidents.respond'],
        ],
    ],
];
