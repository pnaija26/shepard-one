<?php

namespace App\Services;

use Exception;

class OperationsMonitoringException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $codeKey = 'operations_error',
        int $status = 422,
    ) {
        parent::__construct($message, $status);
    }
}
