<?php

return [
    'classifications' => [
        'medical' => 'Medical',
        'child_safety' => 'Child safety',
        'security' => 'Security',
        'equipment' => 'Equipment',
        'complaint' => 'Complaint',
        'technical' => 'Technical',
    ],

    'priorities' => [
        'low',
        'normal',
        'high',
        'critical',
    ],

    'statuses' => [
        'open',
        'investigating',
        'escalated',
        'resolved',
        'pending_review',
        'closed',
        'returned',
    ],

    'open_statuses' => [
        'open',
        'investigating',
        'escalated',
        'returned',
    ],

    'activity_types' => [
        'investigation',
        'action',
        'reassignment',
        'escalation',
        'resolution',
        'review_approved',
        'review_returned',
    ],

    'restricted_classifications' => [
        'medical',
        'child_safety',
        'security',
    ],

    'classification_routing' => [
        'medical' => ['team' => 'medical_response', 'label' => 'Medical Response Team'],
        'child_safety' => ['team' => 'child_safety', 'label' => 'Child Safety Team'],
        'security' => ['team' => 'security', 'label' => 'Security Team'],
        'equipment' => ['team' => 'facilities', 'label' => 'Facilities Team'],
        'complaint' => ['team' => 'pastoral', 'label' => 'Pastoral Response'],
        'technical' => ['team' => 'technical', 'label' => 'Technical Team'],
    ],

    'priority_escalation_hours' => [
        'critical' => 0,
        'high' => 4,
        'normal' => 24,
        'low' => 48,
    ],

    'escalation_triggers' => [
        'overdue',
        'critical',
    ],

    'evidence' => [
        'max_items' => 5,
        'max_reference_length' => 500,
    ],
];
