<?php

return [
    'outcomes' => [
        'contacted',
        'assisted_further',
        'no_response',
        'resolved',
        'unresolved',
        'referred',
    ],

    'further_actions' => [
        'continue',
        'reschedule',
        'reassign',
        'escalate',
        'close',
    ],

    'closure_reasons' => [
        'resolved',
        'declined_further_help',
        'referred_externally',
        'duplicate',
        'other',
    ],

    /**
     * Statuses that may receive follow-up activity (delivered or pending assistance).
     */
    'follow_up_eligible_statuses' => [
        'approved',
        'disbursed',
        'follow_up',
        'pending_approval',
    ],

    /**
     * Statuses from which a case may be closed with evidence.
     */
    'close_from_statuses' => [
        'follow_up',
        'disbursed',
        'rejected',
    ],

    /**
     * Allowed status transitions for welfare cases (Story 7.5 AC1).
     * Keys are current status; values are permitted next statuses.
     */
    'transitions' => [
        'draft' => ['submitted'],
        'submitted' => ['under_assessment', 'returned_for_info', 'rejected'],
        'under_assessment' => ['pending_review', 'returned_for_info', 'escalated', 'rejected', 'pending_approval'],
        'returned_for_info' => ['submitted', 'under_assessment'],
        'pending_review' => ['pending_approval', 'approved', 'rejected', 'escalated'],
        'pending_approval' => ['approved', 'rejected', 'escalated', 'pending_review'],
        'escalated' => ['under_assessment', 'pending_approval', 'rejected'],
        'approved' => ['disbursed', 'follow_up', 'rejected'],
        'rejected' => ['closed'],
        'disbursed' => ['follow_up', 'closed'],
        'follow_up' => ['follow_up', 'closed', 'escalated'],
        'closed' => [],
    ],

    'default_due_days' => 7,

    'overdue' => [
        'reminder_after_due_days' => 0,
        'escalate_after_due_days' => 3,
        'reminder_cooldown_hours' => 24,
    ],

    'evidence_constraints' => [
        'max_items' => 5,
        'max_size_bytes' => 10 * 1024 * 1024,
        'allowed_mime_types' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
        ],
        'blocked_extensions' => [
            'exe', 'bat', 'cmd', 'php', 'js', 'sh', 'dll', 'msi', 'apk',
        ],
    ],

    'beneficiary_status_messages' => [
        'follow_up' => 'Your welfare case is in follow-up. An officer may contact you about outcomes.',
        'closed' => 'Your welfare case has been closed.',
        'escalated' => 'Your welfare case has been escalated for further attention.',
    ],
];
