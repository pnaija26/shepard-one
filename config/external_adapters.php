<?php

return [
    'environments' => ['sandbox', 'production'],

    'drain_policies' => ['drain', 'retry', 'cancel'],

    'default_timeout_ms' => (int) env('EXTERNAL_ADAPTER_TIMEOUT_MS', 10000),

    'max_attempts' => (int) env('EXTERNAL_ADAPTER_MAX_ATTEMPTS', 3),

    'backoff_seconds' => [30, 120, 600],

    'adapter_types' => [
        'email' => ['label' => 'Email', 'capabilities' => ['send_email']],
        'sms' => ['label' => 'SMS', 'capabilities' => ['send_sms']],
        'push' => ['label' => 'Push', 'capabilities' => ['send_push']],
        'payment' => ['label' => 'Payment', 'capabilities' => ['capture_payment']],
        'whatsapp' => ['label' => 'WhatsApp', 'capabilities' => ['send_whatsapp']],
        'website' => ['label' => 'Website', 'capabilities' => ['publish_content']],
        'livestream' => ['label' => 'Livestream', 'capabilities' => ['start_stream']],
        'accounting' => ['label' => 'Accounting', 'capabilities' => ['sync_ledger']],
        'identity' => ['label' => 'Identity', 'capabilities' => ['verify_identity']],
        'storage' => ['label' => 'Storage', 'capabilities' => ['store_object']],
        'productivity' => ['label' => 'Productivity', 'capabilities' => ['sync_calendar']],
    ],

    'providers' => [
        'sendgrid' => [
            'adapter_type' => 'email',
            'label' => 'SendGrid',
            'credential_fields' => ['api_key'],
            'health_path' => '/v3/user/profile',
        ],
        'mailgun' => [
            'adapter_type' => 'email',
            'label' => 'Mailgun',
            'credential_fields' => ['api_key', 'domain'],
            'health_path' => '/v3/domains',
        ],
        'twilio' => [
            'adapter_type' => 'sms',
            'label' => 'Twilio',
            'credential_fields' => ['account_sid', 'auth_token'],
            'health_path' => '/2010-04-01/Accounts.json',
        ],
        'termii' => [
            'adapter_type' => 'sms',
            'label' => 'Termii',
            'credential_fields' => ['api_key'],
            'health_path' => '/api/sms/send',
        ],
        'firebase' => [
            'adapter_type' => 'push',
            'label' => 'Firebase Cloud Messaging',
            'credential_fields' => ['server_key'],
            'health_path' => '/fcm/send',
        ],
        'stripe' => [
            'adapter_type' => 'payment',
            'label' => 'Stripe',
            'credential_fields' => ['api_key', 'webhook_secret'],
            'health_path' => '/v1/balance',
        ],
        'paystack' => [
            'adapter_type' => 'payment',
            'label' => 'Paystack',
            'credential_fields' => ['secret_key'],
            'health_path' => '/balance',
        ],
        'whatsapp_cloud' => [
            'adapter_type' => 'whatsapp',
            'label' => 'WhatsApp Business Cloud',
            'credential_fields' => ['access_token', 'phone_number_id'],
            'health_path' => '/v17.0/me',
        ],
        'wordpress' => [
            'adapter_type' => 'website',
            'label' => 'WordPress',
            'credential_fields' => ['api_token', 'site_url'],
            'health_path' => '/wp-json/wp/v2/users/me',
        ],
        'youtube' => [
            'adapter_type' => 'livestream',
            'label' => 'YouTube Live',
            'credential_fields' => ['api_key', 'channel_id'],
            'health_path' => '/youtube/v3/channels',
        ],
        'quickbooks' => [
            'adapter_type' => 'accounting',
            'label' => 'QuickBooks',
            'credential_fields' => ['client_id', 'client_secret', 'realm_id'],
            'health_path' => '/v3/company/info',
        ],
        'oidc_generic' => [
            'adapter_type' => 'identity',
            'label' => 'Generic OIDC',
            'credential_fields' => ['client_id', 'client_secret', 'issuer_url'],
            'health_path' => '/.well-known/openid-configuration',
        ],
        's3' => [
            'adapter_type' => 'storage',
            'label' => 'Amazon S3',
            'credential_fields' => ['access_key', 'secret_key', 'bucket'],
            'health_path' => '/',
        ],
        'google_workspace' => [
            'adapter_type' => 'productivity',
            'label' => 'Google Workspace',
            'credential_fields' => ['client_id', 'client_secret', 'refresh_token'],
            'health_path' => '/calendar/v3/users/me/calendarList',
        ],
    ],
];
