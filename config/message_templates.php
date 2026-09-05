<?php

return [
    'statuses' => [
        'draft',
        'published',
        'retired',
    ],

    'version_statuses' => [
        'draft',
        'published',
        'superseded',
    ],

    'channels' => [
        'email' => [
            'label' => 'Email',
            'max_subject' => 200,
            'max_body' => 20000,
            'allows_html' => true,
        ],
        'sms' => [
            'label' => 'SMS',
            'max_subject' => 0,
            'max_body' => 320,
            'allows_html' => false,
        ],
    ],

    'languages' => [
        'en',
        'fr',
        'es',
        'pt',
        'sw',
    ],

    'scenarios' => [
        'birthday' => [
            'label' => 'Birthday greeting',
            'variables' => ['first_name', 'preferred_name', 'branch_name', 'church_name', 'date'],
        ],
        'anniversary' => [
            'label' => 'Anniversary greeting',
            'variables' => ['first_name', 'preferred_name', 'branch_name', 'church_name', 'date', 'years'],
        ],
        'welcome' => [
            'label' => 'Welcome / onboarding',
            'variables' => ['first_name', 'preferred_name', 'branch_name', 'church_name', 'event_name'],
        ],
        'announcement' => [
            'label' => 'General announcement',
            'variables' => ['first_name', 'preferred_name', 'branch_name', 'church_name', 'event_name', 'date'],
        ],
        'reminder' => [
            'label' => 'Reminder',
            'variables' => ['first_name', 'preferred_name', 'branch_name', 'event_name', 'date', 'time'],
        ],
        'pastoral' => [
            'label' => 'Pastoral message',
            'variables' => ['first_name', 'preferred_name', 'branch_name', 'church_name'],
        ],
        'welfare' => [
            'label' => 'Welfare update',
            'variables' => ['first_name', 'preferred_name', 'branch_name', 'reference'],
        ],
        'custom' => [
            'label' => 'Custom',
            'variables' => [
                'first_name',
                'last_name',
                'preferred_name',
                'branch_name',
                'church_name',
                'event_name',
                'date',
                'time',
                'years',
                'reference',
            ],
        ],
    ],

    'sample_data' => [
        'first_name' => 'Alex',
        'last_name' => 'Member',
        'preferred_name' => 'Alex',
        'branch_name' => 'Central Assembly',
        'church_name' => 'ShepardOne Church',
        'event_name' => 'Sunday Service',
        'date' => '2026-08-31',
        'time' => '10:00',
        'years' => '5',
        'reference' => 'REF-DEMO-001',
    ],

    'prohibited_link_patterns' => [
        '/javascript\s*:/i',
        '/data\s*:/i',
        '/vbscript\s*:/i',
    ],

    'unsafe_markup_patterns' => [
        '/<\s*script\b/i',
        '/<\s*iframe\b/i',
        '/<\s*object\b/i',
        '/<\s*embed\b/i',
        '/\bon\w+\s*=/i',
        '/<\s*link\b[^>]*stylesheet/i',
    ],

    'variable_pattern' => '/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/i',
];
