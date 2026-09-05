<?php

namespace App\Services;

/**
 * Story 2.4: blocked lifecycle transition.
 */
class MemberLifecycleTransitionException extends \Exception
{
    /**
     * @param  string[]  $missing
     */
    public function __construct(
        string $message,
        public readonly array $missing = [],
        public readonly bool $requiresApproval = false,
    ) {
        parent::__construct($message);
    }
}
