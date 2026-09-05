<?php

return [
    'delivery_methods' => [
        'cash',
        'bank_transfer',
        'mobile_money',
        'cheque',
        'in_kind',
        'voucher',
        'other',
    ],

    'confirmation_statuses' => [
        'pending',
        'confirmed',
        'waived',
    ],

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

    'beneficiary_status_messages' => [
        'disbursed' => 'Assistance has been recorded as delivered for your request.',
        'follow_up' => 'Your request has moved to follow-up after assistance delivery.',
    ],
];
