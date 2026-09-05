<?php

namespace App\Services;

/**
 * Story 2.6: member not eligible to display or verify a card.
 */
class MembershipCardIneligibleException extends \Exception
{
    public function __construct(
        string $message,
        public readonly array $reasons = [],
    ) {
        parent::__construct($message);
    }
}
