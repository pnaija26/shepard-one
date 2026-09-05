<?php

return [
    'levels' => [
        'branch',
        'hq',
        'executive',
        'finance',
    ],

    'decisions' => [
        'approved',
        'rejected',
        'returned',
        'escalated',
    ],

    'step_statuses' => [
        'pending',
        'approved',
        'rejected',
        'returned',
        'escalated',
        'skipped',
    ],

    /*
     * Default threshold bands (max_value inclusive). Null max_value = open-ended.
     * Proposed value selects the first band where value <= max_value (or open-ended).
     */
    'default_thresholds' => [
        ['max_value' => 50000, 'levels' => ['branch']],
        ['max_value' => 200000, 'levels' => ['branch', 'hq']],
        ['max_value' => 500000, 'levels' => ['branch', 'hq', 'executive']],
        ['max_value' => null, 'levels' => ['branch', 'hq', 'executive', 'finance']],
    ],

    'level_permissions' => [
        'branch' => 'welfare.approvals.branch',
        'hq' => 'welfare.approvals.hq',
        'executive' => 'welfare.approvals.executive',
        'finance' => 'welfare.approvals.finance',
    ],

    'beneficiary_status_messages' => [
        'pending_approval' => 'Your request is progressing through approval.',
        'approved' => 'Your request has been approved.',
        'rejected' => 'Your request was not approved.',
        'returned_for_info' => 'Additional information is needed before approval can continue.',
    ],

    'in_flight_policy' => 'locked_version',
];
