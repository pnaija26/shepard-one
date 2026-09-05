<?php

return [
    'types' => [
        'cell',
        'fellowship',
        'class',
        'interest_group',
    ],

    'statuses' => [
        'draft',
        'active',
        'archived',
    ],

    'leader_roles' => [
        'lead',
        'co_lead',
        'facilitator',
    ],

    'membership_roles' => [
        'leader',
        'co_leader',
        'member',
        'assistant',
    ],

    'membership_statuses' => [
        'pending',
        'active',
        'transferred',
        'removed',
    ],

    'active_membership_statuses' => [
        'pending',
        'active',
    ],

    'join_request_statuses' => [
        'pending',
        'approved',
        'rejected',
    ],

    'meeting_frequencies' => [
        'weekly',
        'biweekly',
        'monthly',
        'custom',
    ],

    'eligible_lifecycle_statuses' => [
        'active',
    ],

    'default_eligibility' => [
        'min_age' => null,
        'max_age' => null,
        'lifecycle_stages' => [],
        'requires_consent' => true,
        'requires_safeguarding_clearance' => false,
    ],
];
