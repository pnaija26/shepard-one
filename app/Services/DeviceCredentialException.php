<?php

namespace App\Services;

use Exception;

class DeviceCredentialException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $codeKey = 'device_credential_error',
        int $status = 422,
    ) {
        parent::__construct($message, $status);
    }
}
