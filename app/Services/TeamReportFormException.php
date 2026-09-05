<?php

namespace App\Services;

use Exception;

/**
 * Story 5.7: incompatible report form schema change.
 */
class TeamReportFormException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
