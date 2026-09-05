<?php

return [
    'max_fields' => 12,
    'max_group_by' => 3,
    'max_sorts' => 3,
    'max_row_limit' => 500,
    'max_cost_score' => 2500,

    'calculations' => [
        'count' => [
            'label' => 'Count rows',
            'aggregates' => ['*'],
            'numeric_only' => false,
        ],
        'sum' => [
            'label' => 'Sum',
            'aggregates' => ['numeric'],
            'numeric_only' => true,
        ],
        'avg' => [
            'label' => 'Average',
            'aggregates' => ['numeric'],
            'numeric_only' => true,
        ],
    ],

    'filters' => [
        'branch' => ['label' => 'Branch', 'type' => 'branch_id'],
        'date' => ['label' => 'Date range', 'type' => 'date_range'],
        'age' => ['label' => 'Age range', 'type' => 'age_range'],
        'gender' => ['label' => 'Gender', 'type' => 'enum'],
        'membership_stage' => ['label' => 'Lifecycle stage', 'type' => 'enum', 'field' => 'lifecycle_stage'],
        'membership_status' => ['label' => 'Lifecycle status', 'type' => 'enum', 'field' => 'lifecycle_status'],
        'team' => ['label' => 'Service team', 'type' => 'team_id'],
        'group' => ['label' => 'Church group', 'type' => 'group_id'],
        'attendance_status' => ['label' => 'Attendance status', 'type' => 'enum', 'field' => 'status'],
        'event' => ['label' => 'Event session', 'type' => 'event_id'],
        'welfare_type' => ['label' => 'Welfare request type', 'type' => 'enum', 'field' => 'request_type'],
        'location_city' => ['label' => 'City', 'type' => 'string', 'field' => 'city'],
    ],

    'join_rules' => [],

    'data_sources' => [
        'members' => [
            'label' => 'Members',
            'permission' => 'members.read',
            'model' => \App\Models\Member::class,
            'date_fields' => ['created_at', 'date_of_birth'],
            'default_date_field' => 'created_at',
            'fields' => [
                'membership_id' => ['label' => 'Membership ID', 'type' => 'string'],
                'first_name' => ['label' => 'First name', 'type' => 'string'],
                'last_name' => ['label' => 'Last name', 'type' => 'string'],
                'lifecycle_stage' => ['label' => 'Lifecycle stage', 'type' => 'string'],
                'lifecycle_status' => ['label' => 'Lifecycle status', 'type' => 'string'],
                'gender' => ['label' => 'Gender', 'type' => 'string'],
                'city' => ['label' => 'City', 'type' => 'string'],
                'branch_id' => ['label' => 'Branch ID', 'type' => 'integer'],
                'created_at' => ['label' => 'Registered at', 'type' => 'datetime'],
                'email' => ['label' => 'Email', 'type' => 'string', 'sensitive' => true, 'permission' => 'members.sensitive'],
                'phone' => ['label' => 'Phone', 'type' => 'string', 'sensitive' => true, 'permission' => 'members.sensitive'],
                'date_of_birth' => ['label' => 'Date of birth', 'type' => 'date', 'sensitive' => true, 'permission' => 'members.sensitive'],
            ],
        ],
        'visitors' => [
            'label' => 'Visitors',
            'permission' => 'visitors.read',
            'model' => \App\Models\Visitor::class,
            'date_fields' => ['created_at', 'first_visit_at'],
            'default_date_field' => 'created_at',
            'fields' => [
                'first_name' => ['label' => 'First name', 'type' => 'string'],
                'last_name' => ['label' => 'Last name', 'type' => 'string'],
                'original_source' => ['label' => 'Source', 'type' => 'string'],
                'branch_id' => ['label' => 'Branch ID', 'type' => 'integer'],
                'created_at' => ['label' => 'Captured at', 'type' => 'datetime'],
                'first_visit_at' => ['label' => 'First visit', 'type' => 'datetime'],
            ],
        ],
        'attendance' => [
            'label' => 'Attendance',
            'permission' => 'attendance.read',
            'model' => \App\Models\AttendanceRecord::class,
            'date_fields' => ['gathering_date', 'captured_at'],
            'default_date_field' => 'gathering_date',
            'fields' => [
                'service_type' => ['label' => 'Service type', 'type' => 'string'],
                'status' => ['label' => 'Status', 'type' => 'string'],
                'gathering_date' => ['label' => 'Gathering date', 'type' => 'date'],
                'branch_id' => ['label' => 'Branch ID', 'type' => 'integer'],
                'capture_method' => ['label' => 'Capture method', 'type' => 'string'],
            ],
        ],
        'welfare' => [
            'label' => 'Welfare requests',
            'permission' => 'welfare.reports.read',
            'model' => \App\Models\WelfareRequest::class,
            'date_fields' => ['created_at', 'submitted_at'],
            'default_date_field' => 'created_at',
            'fields' => [
                'case_number' => ['label' => 'Case number', 'type' => 'string'],
                'request_type' => ['label' => 'Request type', 'type' => 'string'],
                'status' => ['label' => 'Status', 'type' => 'string'],
                'requested_value' => ['label' => 'Requested value', 'type' => 'numeric'],
                'approved_value' => ['label' => 'Approved value', 'type' => 'numeric'],
                'branch_id' => ['label' => 'Branch ID', 'type' => 'integer'],
                'created_at' => ['label' => 'Created at', 'type' => 'datetime'],
                'beneficiary_name' => [
                    'label' => 'Beneficiary name',
                    'type' => 'string',
                    'sensitive' => true,
                    'permission' => 'welfare.reports.identity.read',
                ],
            ],
        ],
    ],
];
