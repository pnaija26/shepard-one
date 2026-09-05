<?php

return [
    'roles' => [
        'member',
        'lead',
        'assistant',
        'trainee',
    ],

    'statuses' => [
        'pending',
        'scheduled',
        'active',
        'transferred',
        'removed',
    ],

    'active_statuses' => [
        'pending',
        'scheduled',
        'active',
    ],

    'eligible_lifecycle_statuses' => [
        'active',
    ],

    'blocked_lifecycle_statuses' => [
        'inactive',
        'suspended',
        'deceased',
        'archived',
        'transferred',
    ],

    'max_active_teams_per_member' => 3,

    'conflict_reasons' => [
        'ineligible_member',
        'branch_mismatch',
        'max_teams_exceeded',
        'duplicate_assignment',
        'shift_conflict',
        'team_capacity',
        'missing_skills',
    ],
];
