<?php

return [
    'types' => [
        'weekly',
        'monthly',
        'event',
        'shift',
    ],

    'roster_statuses' => [
        'draft',
        'published',
        'cancelled',
    ],

    'slot_statuses' => [
        'draft',
        'published',
        'accepted',
        'rejected',
        'replacement_requested',
        'substituted',
        'cancelled',
    ],

    'member_responses' => [
        'accepted',
        'rejected',
        'replacement_requested',
    ],

    'gathering_keys' => [
        'church_service',
        'church_event',
    ],

    'conflict_reasons' => [
        'ineligible_member',
        'branch_mismatch',
        'unavailable',
        'duplicate_roster_assignment',
        'duplicate_shift',
        'missing_skills',
        'staffing_shortfall',
    ],
];
