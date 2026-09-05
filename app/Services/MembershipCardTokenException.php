<?php

namespace App\Services;

/**
 * Story 2.6: invalid, expired, altered, or replayed card tokens.
 */
class MembershipCardTokenException extends \Exception
{
    public function __construct(
        string $message,
        public readonly string $reason = 'invalid',
    ) {
        parent::__construct($message);
    }
}
