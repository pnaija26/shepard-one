<?php

return [
    'interactive_row_threshold' => 100,

    'download_ttl_hours' => 24,

    'max_attempts' => 3,

    'formats' => [
        'csv' => [
            'label' => 'CSV',
            'mime' => 'text/csv',
            'extension' => 'csv',
        ],
        'excel' => [
            'label' => 'Excel',
            'mime' => 'application/vnd.ms-excel',
            'extension' => 'xls',
        ],
        'pdf' => [
            'label' => 'PDF',
            'mime' => 'application/pdf',
            'extension' => 'pdf',
        ],
        'print' => [
            'label' => 'Print',
            'mime' => 'text/html',
            'extension' => 'html',
        ],
        'dashboard' => [
            'label' => 'Dashboard snapshot',
            'mime' => 'application/json',
            'extension' => 'json',
        ],
        'email' => [
            'label' => 'Email delivery',
            'mime' => 'text/csv',
            'extension' => 'csv',
        ],
    ],

    'classifications' => [
        'internal',
        'restricted',
        'confidential',
    ],
];
