<?php

namespace App\Services;

use Exception;

/**
 * Story 5.4: roster validation or publication rejection.
 */
class TeamRosterException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly int $status = 422,
        public readonly bool $overridable = true,
        public readonly array $conflicts = [],
    ) {
        parent::__construct($message);
    }
}
