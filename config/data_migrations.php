<?php

return [
    'storage_disk' => env('DATA_MIGRATION_DISK', 'local'),

    'retention_days' => (int) env('DATA_MIGRATION_RETENTION_DAYS', 90),

    'sample_test_rows' => 5,

    'source_types' => [
        'csv',
        'excel',
        'database',
        'membership_system',
    ],

    'target_entities' => [
        'members' => [
            'label' => 'Members',
            'required_fields' => ['first_name', 'last_name', 'membership_id', 'branch_id'],
            'optional_fields' => ['email', 'phone', 'preferred_name', 'lifecycle_status'],
            'import_model' => \App\Models\Member::class,
        ],
        'households' => [
            'label' => 'Households',
            'required_fields' => ['name', 'branch_id'],
            'optional_fields' => ['shared_email', 'shared_phone'],
            'import_model' => \App\Models\Household::class,
        ],
    ],

    'transformations' => [
        'trim',
        'lowercase',
        'uppercase',
        'title_case',
        'date_iso',
        'phone_normalize',
    ],

    'duplicate_strategies' => [
        'reject',
        'review',
        'keep_first',
    ],

    'sensitive_column_patterns' => [
        'password',
        'ssn',
        'national_id',
        'bank',
        'account_number',
        'card',
        'salary',
        'medical',
    ],

    'membership_systems' => [
        'planning_center' => [
            'label' => 'Planning Center',
            'columns' => ['first_name', 'last_name', 'email', 'phone', 'membership_id'],
        ],
        'church_windows' => [
            'label' => 'Church Windows',
            'columns' => ['FirstName', 'LastName', 'Email', 'Phone', 'MemberID'],
        ],
    ],

    'default_acceptance_thresholds' => [
        'max_error_rate' => 0.05,
        'min_success_rate' => 0.95,
    ],

    'registration_channel' => 'legacy_migration',
];
