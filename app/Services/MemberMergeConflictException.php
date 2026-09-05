<?php

namespace App\Services;

/**
 * Story 2.5 AC3: merge blocked due to restricted record conflicts.
 */
class MemberMergeConflictException extends \Exception
{
    public function __construct(
        string $message,
        public readonly array $conflicts = [],
    ) {
        parent::__construct($message);
    }
}
