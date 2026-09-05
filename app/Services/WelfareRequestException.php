<?php

namespace App\Services;

use RuntimeException;

class WelfareRequestException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public function __construct(
        string $message,
        private string $codeKey,
        private int $httpStatus = 422,
        private ?array $details = null,
    ) {
        parent::__construct($message);
    }

    public function codeKey(): string
    {
        return $this->codeKey;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function details(): ?array
    {
        return $this->details;
    }
}
