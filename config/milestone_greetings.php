<?php

return [
    'types' => [
        'birthday' => [
            'label' => 'Birthday',
            'source' => 'date_of_birth',
            'template_scenario' => 'birthday',
            'period' => 'yearly',
        ],
        'wedding' => [
            'label' => 'Wedding anniversary',
            'source' => 'milestone',
            'template_scenario' => 'anniversary',
            'period' => 'yearly',
        ],
        'membership' => [
            'label' => 'Membership anniversary',
            'source' => 'milestone',
            'template_scenario' => 'anniversary',
            'period' => 'yearly',
        ],
        'baptism' => [
            'label' => 'Baptism anniversary',
            'source' => 'milestone',
            'template_scenario' => 'anniversary',
            'period' => 'yearly',
        ],
        'ordination' => [
            'label' => 'Ordination anniversary',
            'source' => 'milestone',
            'template_scenario' => 'anniversary',
            'period' => 'yearly',
        ],
        'service' => [
            'label' => 'Service anniversary',
            'source' => 'milestone',
            'template_scenario' => 'anniversary',
            'period' => 'yearly',
        ],
    ],

    // Days before/after the anniversary date to detect and send.
    'detection_window_days' => (int) env('MILESTONE_GREETING_WINDOW_DAYS', 0),

    'excluded_lifecycle_statuses' => [
        'deceased',
        'archived',
        'suspended',
    ],

    // Fields allowed on team birthday/anniversary lists and alerts.
    'approved_list_fields' => [
        'member_id',
        'membership_id',
        'preferred_name',
        'first_name',
        'branch_id',
        'branch_name',
        'milestone_type',
        'milestone_label',
        'occurrence_date', // month-day only in lists (no birth year)
        'years',
    ],

    'default_channels' => ['email', 'in_app'],
];
