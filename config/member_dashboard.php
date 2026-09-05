<?php

return [
  /*
  |--------------------------------------------------------------------------
  | Member dashboard sections (Story 12.2) — lower priority number = higher on page.
  |--------------------------------------------------------------------------
  */
  'sections' => [
    'profile' => ['label' => 'Profile & church', 'priority' => 10],
    'family' => ['label' => 'Family', 'priority' => 20],
    'schedule' => ['label' => 'Schedule', 'priority' => 30],
    'assignments' => ['label' => 'Assignments', 'priority' => 40],
    'groups' => ['label' => 'Groups', 'priority' => 50],
    'teams' => ['label' => 'Teams', 'priority' => 60],
    'attendance' => ['label' => 'Attendance', 'priority' => 70],
    'giving' => ['label' => 'Giving', 'priority' => 80],
    'welfare' => ['label' => 'Welfare', 'priority' => 90],
    'messages' => ['label' => 'Messages', 'priority' => 100],
    'newsletters' => ['label' => 'Newsletters', 'priority' => 110],
    'prayer' => ['label' => 'Prayer', 'priority' => 120],
    'care' => ['label' => 'Pastoral care', 'priority' => 130],
  ],

  'widget_states' => [
    'ready',
    'empty',
    'unavailable',
    'unauthorized',
    'stale',
  ],

  'upcoming_window_days' => 14,
  'attendance_lookback_days' => 90,

  'session_policy' => [
    'clear_cache_on_logout' => true,
    'sensitive_sections' => ['giving', 'welfare', 'prayer', 'care', 'family'],
  ],

  'quick_actions' => [
    ['key' => 'membership_card', 'label' => 'Membership card', 'path' => '/membership-card', 'requires_member' => true, 'priority' => 5],
    ['key' => 'check_in', 'label' => 'Check in', 'path' => '/membership-card', 'requires_member' => true, 'priority' => 10],
    ['key' => 'inbox', 'label' => 'Inbox', 'path' => '/notifications', 'permission' => 'notifications.inbox', 'priority' => 20],
    ['key' => 'prayer', 'label' => 'Prayer request', 'path' => '/prayer', 'permission' => 'prayer.requests.submit.self', 'priority' => 30],
    ['key' => 'welfare', 'label' => 'Welfare request', 'path' => '/welfare', 'permission' => 'welfare.requests.submit.self', 'priority' => 40],
    ['key' => 'giving', 'label' => 'My giving', 'path' => '/my-giving', 'permission' => 'payments.giving.self', 'priority' => 50],
    ['key' => 'profile', 'label' => 'My profile', 'path' => '/my-profile', 'requires_member' => true, 'priority' => 60],
  ],

  'recovery_actions' => [
    'refresh' => ['label' => 'Try again', 'action' => 'refresh'],
    'sign_in' => ['label' => 'Sign in again', 'action' => 'sign_in'],
    'link_profile' => ['label' => 'Contact your administrator', 'action' => 'contact_admin'],
    'go_online' => ['label' => 'Reconnect', 'action' => 'go_online'],
  ],
];
