<?php

use App\Models\ChurchEvent;
use App\Models\ChurchService;

return [
    /*
    |--------------------------------------------------------------------------
    | Story 4.4: capture methods and session types.
    |--------------------------------------------------------------------------
    */
    'capture_methods' => [
        'manual',
        'qr',
        'mobile',
        'member_id',
        'barcode',
        'kiosk',
        'authorized_entry',
    ],

    'session_models' => [
        'church_service' => ChurchService::class,
        'church_event' => ChurchEvent::class,
    ],

    'generic_session_types' => [
        'team',
        'group',
        'training',
        'department',
    ],

    'sync_statuses' => [
        'synced',
        'pending',
        'conflict',
    ],

    'offline_sync_batch_limit' => 100,
];
