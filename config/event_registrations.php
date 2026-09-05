<?php

return [
    'channels' => [
        'web',
        'mobile',
        'staff',
        'qr',
    ],

    'statuses' => [
        'confirmed',
        'waitlisted',
        'cancelled',
    ],

    'payment_statuses' => [
        'not_required',
        'pending',
        'paid',
    ],

    'scan_outcomes' => [
        'admitted',
        'rejected',
    ],

    'credential_ttl_days' => 30,
];