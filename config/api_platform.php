<?php

return [
    'version' => env('API_PLATFORM_VERSION', '1'),

    'correlation_header' => 'X-Correlation-Id',

    'rate_limits' => [
        'default_per_minute' => (int) env('API_PLATFORM_RATE_LIMIT', 120),
        'machine_principal_per_minute' => (int) env('API_PLATFORM_MACHINE_RATE_LIMIT', 60),
    ],

    'deprecation_policy' => [
        'notice_period_days' => 180,
        'sunset_header' => 'Sunset',
        'successor_version' => null,
        'contact' => env('API_PLATFORM_CONTACT', 'integrations@shepardone.church'),
    ],

    'auth_methods' => [
        'session' => [
            'label' => 'Identity session (Sanctum bearer token)',
            'header' => 'Authorization: Bearer {access_token}',
        ],
        'machine_principal' => [
            'label' => 'Typed machine principal',
            'header' => 'Authorization: Bearer {client_id}.{client_secret}',
        ],
        'oidc' => [
            'label' => 'OIDC/OAuth credential',
            'status' => 'planned',
        ],
    ],

    'error_codes' => [
        'unauthenticated' => [
            'http_status' => 401,
            'message' => 'Authentication is required.',
        ],
        'forbidden' => [
            'http_status' => 403,
            'message' => 'Access denied.',
        ],
        'rate_limited' => [
            'http_status' => 429,
            'message' => 'Rate limit exceeded.',
        ],
        'validation_failed' => [
            'http_status' => 422,
            'message' => 'The request could not be validated.',
        ],
        'not_found' => [
            'http_status' => 404,
            'message' => 'The requested resource is not available.',
        ],
        'credential_revoked' => [
            'http_status' => 401,
            'message' => 'The API credential is invalid or revoked.',
        ],
        'credential_malformed' => [
            'http_status' => 401,
            'message' => 'The API credential is malformed.',
        ],
        'scope_insufficient' => [
            'http_status' => 403,
            'message' => 'The credential does not include the required scope.',
        ],
    ],

    'pagination' => [
        'default_per_page' => 25,
        'max_per_page' => 100,
        'cursor_param' => 'cursor',
        'per_page_param' => 'per_page',
    ],

    'schemas' => [
        'member' => [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'membership_id' => ['type' => 'string'],
                'first_name' => ['type' => 'string'],
                'last_name' => ['type' => 'string'],
                'email' => ['type' => 'string', 'nullable' => true],
                'branch_id' => ['type' => 'integer'],
                'lifecycle_status' => ['type' => 'string'],
            ],
        ],
        'member_collection' => [
            'type' => 'object',
            'properties' => [
                'data' => ['type' => 'array', 'items' => ['$ref' => 'member']],
            ],
        ],
        'organization' => [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'type' => ['type' => 'string'],
                'identifier' => ['type' => 'string'],
                'parent_id' => ['type' => 'integer', 'nullable' => true],
            ],
        ],
        'organization_collection' => [
            'type' => 'object',
            'properties' => [
                'data' => ['type' => 'array', 'items' => ['$ref' => 'organization']],
                'meta' => ['type' => 'object'],
            ],
        ],
        'api_error' => [
            'type' => 'object',
            'properties' => [
                'message' => ['type' => 'string'],
                'code' => ['type' => 'string'],
                'correlation_id' => ['type' => 'string'],
            ],
        ],
    ],

    'endpoints' => [
        [
            'name' => 'api.v1.members.index',
            'method' => 'GET',
            'path' => '/api/v1/members',
            'summary' => 'List members within effective branch scope',
            'auth' => ['session', 'machine_principal'],
            'scopes' => ['members.read'],
            'filters' => ['status', 'search'],
            'response_schema' => 'member_collection',
            'rate_limit_per_minute' => 120,
            'example' => [
                'request' => ['query' => ['status' => 'active']],
                'response' => ['data' => [['id' => 1, 'membership_id' => 'M-001']]],
            ],
        ],
        [
            'name' => 'api.v1.members.show',
            'method' => 'GET',
            'path' => '/api/v1/members/{member}',
            'summary' => 'Retrieve a member profile when authorized',
            'auth' => ['session', 'machine_principal'],
            'scopes' => ['members.read'],
            'response_schema' => 'member',
            'rate_limit_per_minute' => 120,
            'example' => [
                'request' => ['path' => ['member' => 1]],
                'response' => ['data' => ['id' => 1, 'membership_id' => 'M-001']],
            ],
        ],
        [
            'name' => 'api.v1.organizations.index',
            'method' => 'GET',
            'path' => '/api/v1/organizations',
            'summary' => 'List organizations within effective scope',
            'auth' => ['session', 'machine_principal'],
            'scopes' => ['organizations.read'],
            'response_schema' => 'organization_collection',
            'rate_limit_per_minute' => 120,
            'example' => [
                'request' => [],
                'response' => ['data' => [['id' => 1, 'name' => 'HQ']], 'meta' => ['scope' => 'church-wide']],
            ],
        ],
    ],
];
