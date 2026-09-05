<?php

return [
    'rule_types' => [
        'consecutive_absence' => 'Consecutive absence from services',
        'declining_attendance' => 'Declining attendance rate',
        'no_return_after_first_visit' => 'No return after first visit',
        'repeated_team_absence' => 'Repeated team or rehearsal absence',
    ],

    'attendance_statuses' => [
        'present',
        'absent',
        'excused',
        'late',
        'online',
        'first_timer',
        'visitor',
    ],

    'present_statuses' => [
        'present',
        'late',
        'online',
    ],

    'excluded_statuses' => [
        'excused',
        'online',
    ],

    'default_parameters' => [
        'consecutive_absence' => [
            'consecutive_count' => 3,
            'lookback_days' => 90,
        ],
        'declining_attendance' => [
            'recent_weeks' => 4,
            'prior_weeks' => 4,
            'min_decline_percent' => 30,
            'min_records' => 4,
        ],
        'no_return_after_first_visit' => [
            'days_since_first' => 14,
        ],
        'repeated_team_absence' => [
            'absence_count' => 3,
            'lookback_days' => 30,
        ],
    ],

  'default_exclusions' => [
        'excused_absence' => true,
        'online_attendance' => true,
        'branch_transfer' => true,
        'service_cancellation' => true,
        'insufficient_history' => true,
    ],

    'correction_policies' => [
        'resolve',
        'flag_review',
        'retain',
    ],

    'default_correction_policy' => 'resolve',
];
