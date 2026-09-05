<?php

namespace App\Services;

/**
 * Story 3.1: potential duplicate visitors or members found during capture.
 */
class VisitorDuplicateException extends \Exception
{
    /**
     * @param  array<int, array{type: string, record: object, confidence: string, reason: string}>  $matches
     */
    public function __construct(
        public readonly array $matches,
        public readonly array $preservedInput,
    ) {
        parent::__construct('Potential duplicate visitor records found.');
    }
}
