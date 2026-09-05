<?php

return [
    'statuses' => [
        'draft',
        'published',
        'archived',
    ],

    'version_statuses' => [
        'draft',
        'published',
    ],

    'migration_policies' => [
        'keep_locked' => 'Existing instances stay on their published version',
        'migrate_pending' => 'Open instances move to the newly published version',
    ],

    'default_migration_policy' => 'keep_locked',

    'trigger_types' => [
        'manual',
        'event',
    ],

    'state_types' => [
        'start',
        'action',
        'approval',
        'rejection',
        'escalation',
        'notification',
        'end',
    ],

    'end_state_types' => [
        'end',
    ],

    /**
     * Permissions that may be referenced as assignment/escalation actors.
     * Privilege escalation is blocked when a draft references permissions
     * outside this allow-list or beyond the publisher's grants.
     */
    'assignable_permissions' => [
        'tasks.manage',
        'tasks.work',
        'followups.manage',
        'followups.work',
        'welfare.requests.manage',
        'welfare.approvals.decide',
        'care.cases.manage',
        'care.cases.escalate',
        'prayer.requests.process',
        'prayer.requests.escalate',
        'workflows.participate',
    ],

    'max_loop_limit' => 10,

    'instance_open_statuses' => [
        'open',
        'in_progress',
        'pending',
    ],

    'participant_decisions' => [
        'approve',
        'reject',
        'return',
        'complete',
        'reassign',
    ],

    'scheduler' => [
        'reminder_cooldown_hours' => (int) env('WORKFLOW_REMINDER_COOLDOWN_HOURS', 24),
        'default_deadline_hours' => (int) env('WORKFLOW_DEFAULT_DEADLINE_HOURS', 24),
    ],

    'required_context_fields' => [
        // Event starts may declare required keys in the workflow definition conditions.
    ],
];
