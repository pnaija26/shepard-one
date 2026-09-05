<?php

namespace App\Services;

use Exception;

class TeamDashboardConflictException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $currentVersion,
        public readonly string $codeKey = 'dashboard_stale',
    ) {
        parent::__construct($message, 409);
    }
}
