<?php

namespace App\Services;

use Exception;

class ReportScheduleException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $codeKey = 'error',
        public readonly int $status = 422,
        public readonly ?array $details = null,
    ) {
        parent::__construct($message, $status);
    }
}
