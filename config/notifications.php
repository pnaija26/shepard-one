<?php

return [
    /*
    | Story 10.2: inbox categories shown to users (newest-first).
    | Types outside these categories are hidden from the inbox.
    */
    'categories' => [
        'service' => 'Service',
        'event' => 'Event',
        'team' => 'Team',
        'welfare' => 'Welfare',
        'care' => 'Care',
        'birthday' => 'Birthday',
        'prayer' => 'Prayer',
        'administrative' => 'Administrative',
        'system' => 'System',
    ],

    /*
    | Map notification type prefixes / exact types → category.
    | First matching prefix wins (longest keys should be listed first where needed).
    */
    'type_categories' => [
        'church_service.' => 'service',
        'service.' => 'service',
        'feedback.' => 'service',
        'incident.' => 'service',
        'church_event.' => 'event',
        'event.' => 'event',
        'team_roster.' => 'team',
        'team_report.' => 'team',
        'service_team.' => 'team',
        'team.' => 'team',
        'training.' => 'team',
        'welfare.' => 'welfare',
        'care.' => 'care',
        'care_case.' => 'care',
        'member.birthday' => 'birthday',
        'birthday.' => 'birthday',
        'anniversary.' => 'birthday',
        'prayer.' => 'prayer',
        'profile.' => 'administrative',
        'member.profile' => 'administrative',
        'onboarding.' => 'administrative',
        'followup.' => 'administrative',
        'automation.' => 'administrative',
        'workflow.' => 'administrative',
        'task.' => 'administrative',
        'communication.announcement' => 'administrative',
        'communication.operational' => 'administrative',
        'communication.engagement' => 'administrative',
        'communication.pastoral' => 'care',
        'communication.emergency' => 'system',
        'communication.system' => 'system',
        'communication.' => 'administrative',
        'system.' => 'system',
    ],

    /*
    | Approved deep-link paths → optional authz action rechecked on open.
    | null means any authenticated owner may follow the link.
    */
    'deep_links' => [
        '/tasks' => 'tasks.read',
        '/workflows' => 'workflows.read',
        '/welfare' => 'welfare.requests.read',
        '/care' => 'care.cases.read',
        '/prayer' => 'prayer.requests.read.self',
        '/events' => null,
        '/services' => null,
        '/teams' => null,
        '/rosters' => null,
        '/training' => null,
        '/profile' => null,
        '/me/profile' => null,
        '/notifications' => null,
        '/communications' => 'communications.read',
        '/automation-rules' => 'automation.rules.read',
    ],

    /*
    | Default deep link by type prefix when metadata.deep_link is absent.
    */
    'default_deep_links' => [
        'task.' => '/tasks',
        'workflow.' => '/workflows',
        'welfare.' => '/welfare',
        'care.' => '/care',
        'care_case.' => '/care',
        'prayer.' => '/prayer',
        'team_roster.' => '/rosters',
        'team_report.' => '/teams',
        'service_team.' => '/teams',
        'training.' => '/training',
        'feedback.' => '/services',
        'event.' => '/events',
        'church_event.' => '/events',
        'profile.' => '/me/profile',
        'communication.' => '/notifications',
    ],

    'page_size' => 50,
];
