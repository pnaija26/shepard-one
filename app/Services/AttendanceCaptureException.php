<?php

namespace App\Services;

use Exception;

/**
 * Story 4.4: safe rejection for attendance capture attempts.
 */
class AttendanceCaptureException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly int $status = 422,
        public readonly ?string $nextStep = null,
    ) {
        parent::__construct($message);
    }
}
