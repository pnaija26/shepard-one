<?php

namespace App\Services;

class EventRegistrationException extends \RuntimeException
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
