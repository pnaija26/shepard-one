<?php

return [
    'statuses' => [
        'under_assessment',
        'returned_for_info',
        'pending_review',
        'escalated',
    ],

    'recommendations' => [
        'approve',
        'partial_approve',
        'decline',
        'defer',
    ],

    'assistance_types' => [
        'cash',
        'in_kind',
        'voucher',
        'referral',
        'other',
    ],

    'condition_types' => [
        'missing_evidence',
        'conflict_of_interest',
        'duplicate_assistance',
        'clarification_needed',
    ],

    'condition_actions' => [
        'missing_evidence' => 'return_for_info',
        'clarification_needed' => 'return_for_info',
        'conflict_of_interest' => 'reassign',
        'duplicate_assistance' => 'escalate',
    ],

    'beneficiary_status_messages' => [
        'submitted' => 'Your request has been received and is awaiting assessment.',
        'under_assessment' => 'Your request is being reviewed by the welfare team.',
        'returned_for_info' => 'Additional information is needed before your request can continue.',
        'pending_review' => 'Your request is awaiting approval review.',
        'escalated' => 'Your request is under senior welfare review.',
        'draft' => 'Your request draft is not yet submitted.',
    ],

    /*
     * Segregation of duties: assessing officers cannot approve these levels.
     * Story 7.3 will implement actual approval routing; 7.2 enforces the block.
     */
    'segregation_of_duties' => [
        'assessor_prohibited_approval_levels' => [
            'branch',
            'hq',
            'executive',
            'finance',
        ],
    ],
];
