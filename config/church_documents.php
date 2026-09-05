<?php

return [
    'storage_disk' => env('CHURCH_DOCUMENTS_DISK', 'local'),

    'classifications' => [
        'public',
        'internal',
        'restricted',
        'confidential',
    ],

    'access_scopes' => [
        'branch_members',
        'record_viewers',
        'staff',
        'role_restricted',
        'confidential_officers',
    ],

    'categories' => [
        'evidence',
        'minutes',
        'policy',
        'form',
        'training_material',
        'report',
        'photo',
        'other',
    ],

    'retention_policies' => [
        'standard_1y' => ['label' => '1 year', 'years' => 1],
        'standard_7y' => ['label' => '7 years', 'years' => 7],
        'permanent' => ['label' => 'Permanent', 'years' => null],
        'legal_hold' => ['label' => 'Legal hold', 'years' => null],
    ],

    'record_types' => [
        'member' => [
            'label' => 'Member',
            'model' => \App\Models\Member::class,
            'permission' => 'members.read',
            'branch_column' => 'branch_id',
            'requires_record_id' => true,
        ],
        'welfare_request' => [
            'label' => 'Welfare request',
            'model' => \App\Models\WelfareRequest::class,
            'permission' => 'welfare.requests.read',
            'branch_column' => 'branch_id',
            'requires_record_id' => true,
        ],
        'care_case' => [
            'label' => 'Care case',
            'model' => \App\Models\CareCase::class,
            'permission' => 'care.cases.read',
            'branch_column' => 'branch_id',
            'requires_record_id' => true,
        ],
        'training_offering' => [
            'label' => 'Training offering',
            'model' => \App\Models\TrainingOffering::class,
            'permission' => 'training.read',
            'branch_column' => 'branch_id',
            'requires_record_id' => true,
        ],
        'group_meeting' => [
            'label' => 'Group meeting',
            'model' => \App\Models\ChurchGroupMeeting::class,
            'permission' => 'groups.meetings.read',
            'branch_column' => 'branch_id',
            'requires_record_id' => true,
        ],
        'service_team' => [
            'label' => 'Service team',
            'model' => \App\Models\ServiceTeam::class,
            'permission' => 'teams.read',
            'branch_column' => 'branch_id',
            'requires_record_id' => true,
        ],
        'church_event' => [
            'label' => 'Church event',
            'model' => \App\Models\ChurchEvent::class,
            'permission' => 'events.read',
            'branch_column' => 'branch_id',
            'requires_record_id' => true,
        ],
        'operational_incident' => [
            'label' => 'Operational incident',
            'model' => \App\Models\OperationalIncident::class,
            'permission' => 'incidents.read',
            'branch_column' => 'branch_id',
            'requires_record_id' => true,
        ],
        'policy' => [
            'label' => 'Policy',
            'permission' => 'documents.upload',
            'requires_record_id' => false,
        ],
        'form' => [
            'label' => 'Form',
            'permission' => 'documents.upload',
            'requires_record_id' => false,
        ],
    ],

    'parent_policies' => [
        'care_case' => [
            'min_classification' => 'restricted',
            'min_access_scope' => 'role_restricted',
        ],
        'welfare_request' => [
            'min_classification' => 'restricted',
            'min_access_scope' => 'staff',
        ],
        'operational_incident' => [
            'min_classification' => 'internal',
            'min_access_scope' => 'staff',
        ],
    ],

    'file_constraints' => [
        'max_size_bytes' => 25 * 1024 * 1024,
        'allowed_mime_types' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
            'text/plain',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ],
        'blocked_extensions' => [
            'exe', 'bat', 'cmd', 'php', 'js', 'sh', 'dll', 'msi', 'apk', 'html', 'htm',
        ],
    ],

    'malware_signatures' => [
        'EICAR-STANDARD-ANTIVIRUS-TEST-FILE',
        '<?php',
        '<script',
        'MZ',
    ],

    'processing_jobs' => [
        'metadata_extract',
        'thumbnail',
    ],

    'download_ttl_minutes' => (int) env('CHURCH_DOCUMENT_DOWNLOAD_TTL_MINUTES', 15),

    'audit_classifications' => [
        'restricted',
        'confidential',
    ],

    'non_deletable_retention_policies' => [
        'permanent',
        'legal_hold',
    ],
];
