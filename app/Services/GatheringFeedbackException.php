<?php

namespace App\Services;

use Exception;

/**
 * Story 4.5: safe rejection for feedback submission.
 */
class GatheringFeedbackException extends Exception
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
