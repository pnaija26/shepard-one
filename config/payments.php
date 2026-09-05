<?php

return [
    /**
     * Only these providers may be configured. Secrets never live in source or logs.
     */
    'approved_providers' => [
        'stripe' => [
            'label' => 'Stripe',
            'signature_header' => 'X-Payment-Signature',
            'supports_test' => true,
        ],
        'paystack' => [
            'label' => 'Paystack',
            'signature_header' => 'X-Payment-Signature',
            'supports_test' => true,
        ],
        'flutterwave' => [
            'label' => 'Flutterwave',
            'signature_header' => 'X-Payment-Signature',
            'supports_test' => true,
        ],
    ],

    'environments' => [
        'sandbox',
        'live',
    ],

    'categories' => [
        'tithe',
        'offering',
        'building_fund',
        'missions',
        'welfare',
        'event',
        'other',
    ],

    'currencies' => [
        'USD',
        'EUR',
        'GBP',
        'NGN',
        'GHS',
        'KES',
        'ZAR',
        'CAD',
        'AUD',
    ],

    'contribution_statuses' => [
        'pending',
        'succeeded',
        'failed',
        'refunded',
        'cancelled',
    ],

    'webhook_statuses' => [
        'received',
        'processed',
        'rejected',
        'replayed',
        'conflict',
    ],

    'signature_tolerance_seconds' => 300,

    'source_types' => [
        'integrated',
        'manual',
        'imported',
    ],

    'reconciliation_statuses' => [
        'unmatched',
        'matched',
        'needs_resolution',
        'reconciled',
    ],

    'resolution_reasons' => [
        'duplicate_reference',
        'amount_mismatch',
        'category_mismatch',
        'member_mismatch',
        'branch_mismatch',
        'other',
    ],

    'receipt_statuses' => [
        'issued',
        'voided',
        'superseded',
    ],

    'adjustment_types' => [
        'void',
        'correction',
        'amount_change',
        'category_change',
    ],

    'manual_provider' => 'manual',

    'member_giving_enabled' => (bool) env('MEMBER_GIVING_ENABLED', true),

    'member_history_statuses' => [
        'succeeded',
    ],

    'member_history_requires_reconciled' => true,

    'report_default_limit' => 200,
];
