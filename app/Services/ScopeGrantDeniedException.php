<?php

namespace App\Services;

use RuntimeException;

/**
 * Story 1.6 AC1: thrown when an administrator attempts to grant a scope they
 * do not possess.
 */
class ScopeGrantDeniedException extends RuntimeException
{
    public function __construct(string $message = 'You cannot grant a scope you do not possess.')
    {
        parent::__construct($message);
    }
}
