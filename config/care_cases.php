<?php

return [
    'categories' => [
        'hospital_visit' => 'Hospital visit',
        'bereavement' => 'Bereavement',
        'counselling' => 'Counselling',
        'marriage_family' => 'Marriage or family need',
        'new_baby' => 'New baby',
        'emergency' => 'Emergency',
        'pastoral_visit' => 'Pastoral visit',
        'follow_up' => 'Follow-up need',
    ],

    'priorities' => [
        'low',
        'normal',
        'high',
        'urgent',
    ],

    'statuses' => [
        'open',
        'assigned',
        'in_progress',
        'escalated',
        'resolved',
        'closed',
    ],

    'open_statuses' => [
        'open',
        'assigned',
        'in_progress',
        'escalated',
    ],

    'confidentiality_levels' => [
        'care_team' => 'Care team only',
        'pastor_only' => 'Pastor only',
        'assigned_only' => 'Assigned caregiver only',
        'safeguarding' => 'Safeguarding clearance',
    ],

    'consent_bases' => [
        'member_request' => 'Member requested care',
        'family_request' => 'Family requested care',
        'pastoral_observation' => 'Pastoral observation with duty of care',
        'emergency' => 'Emergency intervention',
        'referral' => 'Internal referral',
    ],

    'activity_types' => [
        'contact',
        'visit',
        'care_action',
        'outcome',
        'note',
        'follow_up',
    ],

    'outcomes' => [
        'reached',
        'no_response',
        'partial_progress',
        'resolved',
        'unresolved',
        'referred',
        'rescheduled',
    ],

    'escalation_triggers' => [
        'urgency',
        'safeguarding_concern',
        'missed_deadline',
        'unavailable_officer',
        'unresolved_need',
    ],

    'closure_reasons' => [
        'resolved',
        'referred_externally',
        'member_declined',
        'duplicate',
        'other',
    ],

    'data_classification' => 'restricted_sensitive',

    'encrypted_fields' => [
        'description',
        'sensitive_notes',
        'restricted_note',
        'closure_outcome',
        'future_care_plan',
    ],

    'assignee_permission' => 'care.cases.manage',
    'escalation_permission' => 'care.cases.escalate',

    'default_follow_up_days' => 7,
    'missed_deadline_grace_days' => 0,

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
];
