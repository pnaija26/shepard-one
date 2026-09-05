<?php

return [
    'statuses' => [
        'draft',
        'submitted',
        'returned',
        'approved',
    ],

    'review_decisions' => [
        'approved',
        'returned',
    ],

    'standard_sections' => [
        'field_values',
        'attachments',
        'incidents',
        'concerns',
        'results',
        'recommendations',
    ],

    'attachment_constraints' => [
        'max_count' => 10,
        'max_label_length' => 160,
        'allowed_types' => [
            'document',
            'image',
            'link',
        ],
    ],
];
