<?php

return [
    'recurrences' => ['daily', 'weekly', 'monthly'],

    'delivery_channels' => ['email', 'in_app'],

    'max_recipients' => 10,

    'confidential_max_recipients' => 3,

    'restricted_max_recipients' => 5,

    'default_timezone' => 'UTC',

    'recipient_required_permissions' => [
        'standard' => ['reports.standard.read', 'reports.export'],
        'custom' => ['reports.custom.read', 'reports.export'],
    ],
];
