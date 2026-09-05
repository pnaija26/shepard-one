<?php

namespace App\Services;

use Exception;

class WebhookException extends Exception
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message,
        public readonly string $codeKey = 'webhook_error',
        int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message, $status);
    }
}
