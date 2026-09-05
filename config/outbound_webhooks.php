<?php

return [
    'payload_version' => env('OUTBOUND_WEBHOOK_VERSION', '1'),

    'timeout_seconds' => (int) env('OUTBOUND_WEBHOOK_TIMEOUT', 10),

    'max_attempts' => (int) env('OUTBOUND_WEBHOOK_MAX_ATTEMPTS', 5),

    'backoff_seconds' => [60, 300, 900, 3600, 7200],

    'quarantine_after_failures' => (int) env('OUTBOUND_WEBHOOK_QUARANTINE_AFTER', 5),

    'signature_header' => 'X-Webhook-Signature',

    'idempotency_header' => 'X-Webhook-Idempotency-Key',

    'event_id_header' => 'X-Webhook-Event-Id',

    'event_type_header' => 'X-Webhook-Event-Type',

    'version_header' => 'X-Webhook-Version',

    'sensitive_categories' => ['care', 'prayer', 'welfare', 'identity', 'finance'],

    'event_types' => [
        'member.created' => [
            'label' => 'Member created',
            'category' => 'identity',
            'requires_sensitive_approval' => false,
            'fields' => ['id', 'membership_id', 'first_name', 'last_name', 'branch_id', 'lifecycle_status'],
        ],
        'member.updated' => [
            'label' => 'Member updated',
            'category' => 'identity',
            'requires_sensitive_approval' => false,
            'fields' => ['id', 'membership_id', 'first_name', 'last_name', 'branch_id', 'lifecycle_status'],
        ],
        'contribution.reconciled' => [
            'label' => 'Contribution reconciled',
            'category' => 'finance',
            'requires_sensitive_approval' => true,
            'fields' => ['id', 'amount', 'currency', 'category', 'branch_id', 'reconciled_at'],
        ],
        'welfare.request_submitted' => [
            'label' => 'Welfare request submitted',
            'category' => 'welfare',
            'requires_sensitive_approval' => true,
            'fields' => ['id', 'reference', 'status', 'branch_id', 'submitted_at'],
        ],
        'prayer.request_submitted' => [
            'label' => 'Prayer request submitted',
            'category' => 'prayer',
            'requires_sensitive_approval' => true,
            'fields' => ['id', 'reference', 'status', 'branch_id', 'submitted_at'],
        ],
        'care.case_opened' => [
            'label' => 'Care case opened',
            'category' => 'care',
            'requires_sensitive_approval' => true,
            'fields' => ['id', 'reference', 'status', 'branch_id', 'opened_at'],
        ],
    ],
];
