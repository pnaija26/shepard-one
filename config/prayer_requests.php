<?php

return [
    'categories' => [
        'healing' => 'Healing',
        'family' => 'Family',
        'guidance' => 'Guidance',
        'salvation' => 'Salvation',
        'thanksgiving' => 'Thanksgiving',
        'provision' => 'Provision',
        'protection' => 'Protection',
        'other' => 'Other',
    ],

    'priorities' => [
        'low',
        'normal',
        'high',
        'urgent',
    ],

    /**
     * Higher rank = broader audience. Narrowing may only move to a lower or equal rank.
     */
    'confidentiality_scopes' => [
        'private' => ['label' => 'Private', 'rank' => 10],
        'pastor_only' => ['label' => 'Pastor only', 'rank' => 20],
        'prayer_team' => ['label' => 'Prayer team only', 'rank' => 30],
        'group' => ['label' => 'Group', 'rank' => 40],
        'public_testimony' => ['label' => 'Public / Testimony', 'rank' => 50],
    ],

    'statuses' => [
        'submitted',
        'acknowledged',
        'in_prayer',
        'answered',
        'closed',
        'withdrawn',
    ],

    'open_statuses' => [
        'submitted',
        'acknowledged',
        'in_prayer',
    ],

    'activity_types' => [
        'assignment',
        'acknowledgement',
        'update',
        'escalation',
        'answered',
        'closure',
        'group_publication',
    ],

    /**
     * Scopes that prayer-team processors may assign/update (not private).
     */
    'team_processable_scopes' => [
        'prayer_team',
        'group',
        'public_testimony',
    ],

    /**
     * Scopes that only pastor-clearance processors may assign/update.
     */
    'pastor_processable_scopes' => [
        'pastor_only',
        'prayer_team',
        'group',
        'public_testimony',
    ],

    /**
     * Hours after a confidentiality narrowing before indexes are treated as fully propagated.
     * Discovery uses the stricter scope immediately for privacy.
     */
    'propagation_hours' => (int) env('PRAYER_CONFIDENTIALITY_PROPAGATION_HOURS', 1),

    'data_classification' => 'restricted_sensitive',
];
