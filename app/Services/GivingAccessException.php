<?php

namespace App\Services;

use RuntimeException;

class GivingAccessException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message,
        private string $codeKey,
        private int $status = 403,
        private array $details = [],
    ) {
        parent::__construct($message);
    }

    public function codeKey(): string
    {
        return $this->codeKey;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }
}
