<?php

namespace App\Services;

/**
 * Story 2.3 AC3: shared contact cannot overwrite member data without confirmation.
 */
class HouseholdContactOverwriteException extends \Exception
{
    public function __construct(
        public readonly array $conflicts,
        string $message = 'Shared contact would overwrite person-specific data. Confirm to proceed.',
    ) {
        parent::__construct($message);
    }
}
