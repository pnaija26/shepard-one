<?php

namespace App\Services;

use Exception;

class ExternalAdapterException extends Exception
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message,
        public readonly string $codeKey = 'adapter_error',
        int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message, $status);
    }
}
