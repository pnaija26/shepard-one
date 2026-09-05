<?php

return [
    'max_results' => 20,

    'profile_stale_days' => 180,

    'scores' => [
        'skill_match' => 25,
        'training_match' => 20,
        'availability_match' => 25,
        'experience_bonus' => 10,
        'hours_bonus_cap' => 10,
    ],

    'penalties' => [
        'missing_skill' => 15,
        'missing_training' => 20,
        'unavailable' => 40,
        'scheduling_conflict' => 35,
        'stale_profile' => 10,
    ],

    'limitation_messages' => [
        'no_candidates' => 'No eligible volunteers matched the duty requirements.',
        'stale_profile' => 'Volunteer profile has not been updated recently.',
        'scheduling_conflict' => 'Volunteer has a scheduling conflict for this duty.',
        'unavailable' => 'Volunteer marked unavailable for this period.',
        'missing_skills' => 'Volunteer is missing required skills.',
        'missing_training' => 'Volunteer is missing verified training requirements.',
        'ineligible_member' => 'Volunteer is not eligible for team assignment.',
    ],
];
