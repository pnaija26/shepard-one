<?php

return [
    'statuses' => [
        'draft',
        'pending_approval',
        'approved',
        'scheduled',
        'sending',
        'sent',
        'cancelled',
    ],

    'version_statuses' => [
        'draft',
        'approved',
        'superseded',
    ],

    'section_types' => [
        'text' => ['required' => ['body']],
        'image' => ['required' => ['src', 'alt']],
        'button' => ['required' => ['label', 'href']],
        'event' => ['required' => ['title']],
        'announcement' => ['required' => ['title', 'body']],
        'verse' => ['required' => ['reference', 'text']],
        'birthday' => ['required' => []], // filled at send from approved list
        'schedule' => ['required' => ['items']],
        'social_links' => ['required' => ['links']],
        'custom' => ['required' => ['body']],
        'unsubscribe' => ['required' => ['label']],
    ],

    'viewports' => [
        'mobile' => ['width' => 375, 'label' => 'Mobile'],
        'tablet' => ['width' => 768, 'label' => 'Tablet'],
        'desktop' => ['width' => 1200, 'label' => 'Desktop'],
    ],

    'unsafe_markup_patterns' => [
        '/<\s*script\b/i',
        '/<\s*iframe\b/i',
        '/\bon\w+\s*=/i',
        '/javascript\s*:/i',
        '/data\s*:/i',
    ],

    'required_unsubscribe' => true,

    'analytics_metrics' => [
        'sent',
        'delivered',
        'opened',
        'clicked',
        'bounced',
        'unsubscribed',
    ],

    'provider_limitations' => [
        'opened' => 'Open rates depend on client image/proxy support and may under-count privacy-focused clients.',
        'clicked' => 'Click tracking requires tracked links; plain-text clients may not report clicks.',
        'delivered' => 'Delivery confirmation depends on provider webhooks; delays or gaps are possible.',
        'bounced' => 'Soft vs hard bounce classification is provider-specific.',
        'unsubscribed' => 'Unsubscribe events require list-unsubscribe or in-app preference sync.',
    ],

    'batch_size' => (int) env('NEWSLETTER_BATCH_SIZE', 100),
];
