<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Identity Provider Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration defines the identity providers that can be used
    | for authenticating users in the ShepardOne platform.
    |
    */

    'providers' => [
        'default' => env('IDENTITY_PROVIDER', 'local'),

        'local' => [
            'driver' => 'local',
            'model' => App\Models\User::class,
        ],

        'oidc' => [
            'driver' => 'oidc',
            'client_id' => env('OIDC_CLIENT_ID'),
            'client_secret' => env('OIDC_CLIENT_SECRET'),
            'issuer' => env('OIDC_ISSUER'),
            'redirect_uri' => env('OIDC_REDIRECT_URI'),
            'scope' => env('OIDC_SCOPE', 'openid profile email'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Identity Contracts
    |--------------------------------------------------------------------------
    |
    | Defines the canonical identity contracts that all modules and clients
    | should depend on.
    |
    */

    'contracts' => [
        'minimum_identity_context' => [
            'user_id',
            'name',
            'email',
            'roles',
            'permissions'
        ],
        
        'session_context' => [
            'session_id',
            'expires_at',
            'device_info',
            'ip_address'
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for security measures related to identity and access.
    |
    */

    'security' => [
        'lockout_attempts' => env('IDENTITY_LOCKOUT_ATTEMPTS', 5),
        'lockout_duration' => env('IDENTITY_LOCKOUT_DURATION', 30), // minutes
        'recovery_codes' => env('IDENTITY_RECOVERY_CODES', true),

        // Story 1.2 — MFA policy (change here, not in source code).
        'mfa' => [
            // When true, every user must enroll and verify MFA.
            'required_for_all' => env('IDENTITY_MFA_REQUIRED', false),
            // When true, users with a privileged role must enroll and verify MFA.
            'required_for_privileged' => env('IDENTITY_MFA_REQUIRED_FOR_PRIVILEGED', true),
            'privileged_roles' => ['admin', 'hq_admin', 'system_admin'],
        ],
    ],
];