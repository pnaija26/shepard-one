<?php

namespace App\Services;

use Exception;

/**
 * Story 5.2: assignment policy or conflict rejection.
 */
class ServiceTeamAssignmentException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly int $status = 422,
        public readonly bool $overridable = true,
    ) {
        parent::__construct($message);
    }
}
