<?php

namespace App\Services;

use App\Models\Member;

/**
 * Story 2.1 AC2: registration blocked pending duplicate review.
 */
class MemberDuplicateException extends \Exception
{
    /**
     * @param  Member[]  $matches
     */
    public function __construct(
        public readonly array $matches,
        public readonly array $preservedInput,
        string $message = 'Potential duplicate members found. Review required before creating a new record.',
    ) {
        parent::__construct($message);
    }
}
