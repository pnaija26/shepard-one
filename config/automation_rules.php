<?php

return [
    'statuses' => [
        'draft',
        'published',
        'disabled',
        'archived',
    ],

    'version_statuses' => [
        'draft',
        'published',
        'superseded',
    ],

    'supported_events' => [
        'attendance.exception_detected',
        'attendance.recorded',
        'member.birthday',
        'member.anniversary',
        'team.roster_published',
        'team.report_submitted',
        'welfare.request_submitted',
        'welfare.follow_up_due',
        'automation.emitted', // only as intermediate; circular chains blocked
    ],

    'supported_actions' => [
        'create_task' => [
            'label' => 'Create operational task',
            'required_params' => ['title', 'department', 'priority'],
        ],
        'send_notification' => [
            'label' => 'Send in-app notification',
            'required_params' => ['message'],
        ],
        'start_workflow' => [
            'label' => 'Start published workflow',
            'required_params' => ['workflow_id'],
        ],
        'emit_event' => [
            'label' => 'Emit another domain event',
            'required_params' => ['event'],
        ],
        'log_only' => [
            'label' => 'Log match only (no side effect)',
            'required_params' => [],
        ],
    ],

    'stop_behaviors' => [
        'continue' => 'Evaluate remaining matching rules',
        'stop_on_match' => 'Stop after this rule matches',
        'stop_on_success' => 'Stop after this rule executes successfully',
    ],

    'failure_policies' => [
        'retry' => 'Retry until max attempts then quarantine',
        'quarantine' => 'Quarantine immediately on failure',
        'skip' => 'Skip and record failure without retry',
    ],

    'default_failure_policy' => 'retry',

    'max_retries' => (int) env('AUTOMATION_RULE_MAX_RETRIES', 3),
    'max_fan_out' => (int) env('AUTOMATION_RULE_MAX_FAN_OUT', 5),
    'max_emit_depth' => (int) env('AUTOMATION_RULE_MAX_EMIT_DEPTH', 3),

    'priorities' => [
        'low' => 10,
        'normal' => 50,
        'high' => 80,
        'critical' => 100,
    ],
];
