<?php

namespace App\Services;

use Exception;

class ApiPlatformException extends Exception
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message,
        public readonly string $codeKey = 'api_platform_error',
        int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message, $status);
    }
}
