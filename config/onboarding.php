<?php

return [
    'day_offsets' => [0, 1, 3, 7, 14, 30],

    'action_types' => [
        'message',
        'task',
        'reminder',
        'milestone',
        'escalation',
    ],

    'triggers' => [
        'visitor.captured' => 'First-time visitor captured',
        'member.registered' => 'New member registered',
        'member.lifecycle.convert' => 'Member advanced to convert stage',
        'member.lifecycle.member' => 'Member advanced to member stage',
    ],

    'blocked_lifecycle_statuses' => [
        'suspended',
        'deceased',
        'archived',
    ],

    'channels' => [
        'email',
        'sms',
        'in_app',
    ],
];
