<?php

return [
    'statuses' => [
        'draft',
        'published',
    ],

    'field_types' => [
        'text',
        'number',
        'date',
        'dropdown',
        'attachment',
        'image',
        'percentage',
        'rating',
        'checkbox',
    ],

    'attachment_constraints' => [
        'max_size_mb' => 10,
        'allowed_mime_types' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
        ],
    ],

    'rating' => [
        'min' => 1,
        'max' => 5,
    ],
];
