<?php

return [
    'statuses' => [
        'assigned',
        'in_progress',
        'successful',
        'unsuccessful',
        'declined',
        'closed',
        'escalated',
    ],

    'open_statuses' => [
        'assigned',
        'in_progress',
        'unsuccessful',
        'declined',
        'escalated',
    ],

    'priorities' => [
        'low',
        'normal',
        'high',
        'urgent',
    ],

    'contact_methods' => [
        'phone',
        'email',
        'sms',
        'visit',
        'in_app',
    ],

    'outcomes' => [
        'reached',
        'no_answer',
        'wrong_number',
        'successful',
        'unsuccessful',
        'declined',
        'rescheduled',
    ],

    'next_actions' => [
        'continue',
        'close',
        'reschedule',
        'escalate',
    ],

    'source_types' => [
        'manual',
        'attendance_exception',
        'visitor_capture',
        'group_meeting',
    ],

    'escalation_triggers' => [
        'overdue',
        'unsuccessful',
        'declined',
        'high_risk',
    ],

    'escalation' => [
        'high_risk_priorities' => ['high', 'urgent'],
        'role_name' => 'follow_up_lead',
    ],

    'default_due_days' => 3,
];
