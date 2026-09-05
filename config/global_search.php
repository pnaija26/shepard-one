<?php

return [
    'query_min_length' => 2,

    'max_results_total' => 50,

    'max_results_per_type' => 10,

    'target_duration_ms' => 2000,

    'sync_max_attempts' => 3,

    'sync_retry_minutes' => 15,

    'record_types' => [
        'member' => [
            'label' => 'Members',
            'permission' => 'members.read',
            'route' => '/members',
        ],
        'household' => [
            'label' => 'Households',
            'permission' => 'households.read',
            'route' => '/households',
        ],
        'branch' => [
            'label' => 'Branches',
            'permission' => 'organizations.read',
            'route' => '/organizations',
        ],
        'service_team' => [
            'label' => 'Service teams',
            'permission' => 'teams.read',
            'route' => '/service-teams',
        ],
        'church_group' => [
            'label' => 'Groups',
            'permission' => 'groups.read',
            'route' => '/groups',
        ],
        'church_event' => [
            'label' => 'Events',
            'permission' => 'events.read',
            'route' => '/events',
        ],
        'attendance_record' => [
            'label' => 'Attendance',
            'permission' => 'attendance.read',
            'route' => '/attendance-capture',
        ],
        'welfare_request' => [
            'label' => 'Welfare requests',
            'permission' => 'welfare.requests.read',
            'route' => '/welfare',
            'sensitivity' => 'restricted',
        ],
        'care_case' => [
            'label' => 'Care cases',
            'permission' => 'care.cases.read',
            'route' => '/care',
            'sensitivity' => 'restricted',
        ],
        'custom_report' => [
            'label' => 'Custom reports',
            'permission' => 'reports.custom.read',
            'route' => '/custom-reports',
        ],
        'church_document' => [
            'label' => 'Documents',
            'permission' => 'documents.read',
            'route' => '/church-documents',
        ],
    ],
];
