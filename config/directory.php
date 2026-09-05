<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Propagation window before visibility changes appear in directory results.
    |--------------------------------------------------------------------------
    */
    'propagation_seconds' => (int) env('DIRECTORY_PROPAGATION_SECONDS', 300),

    /*
    |--------------------------------------------------------------------------
    | Visibility levels members can choose per eligible field.
    |--------------------------------------------------------------------------
    */
    'visibility_levels' => [
        'hidden' => 'Hidden from directory',
        'congregation' => 'Visible to directory users in my branch',
        'staff' => 'Visible to authorized staff only',
        'household' => 'Visible to my household members only',
    ],

    'default_visibility' => 'hidden',

    /*
    |--------------------------------------------------------------------------
    | Fields members may control. Forbidden fields are never publishable.
    |--------------------------------------------------------------------------
    */
    'fields' => [
        'photo_path' => ['label' => 'Photograph'],
        'phone' => ['label' => 'Phone'],
        'email' => ['label' => 'Email'],
        'branch' => ['label' => 'Branch'],
        'department' => ['label' => 'Department'],
        'team' => ['label' => 'Team'],
        'group' => ['label' => 'Group'],
    ],

    'forbidden_fields' => [
        'first_name',
        'last_name',
        'preferred_name',
        'membership_id',
        'date_of_birth',
        'gender',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'postal_code',
        'country',
        'occupation',
        'emergency_contact',
        'restricted_summaries',
        'spiritual_gifts',
        'skills',
        'ministry_interests',
        'communication_preferences',
        'membership_status',
        'lifecycle_status',
    ],
];
