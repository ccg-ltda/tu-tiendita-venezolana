<?php

namespace App\Services;

use RuntimeException;

class AppsScriptCheckoutException extends RuntimeException
{
    public function __construct(
        private readonly int $status,
        private readonly ?string $remoteCode = null,
        private readonly bool $retryable = false,
    ) {
        parent::__construct('Apps Script checkout is unavailable.');
    }

    public function status(): int
    {
        return $this->status;
    }

    public function remoteCode(): ?string
    {
        return $this->remoteCode;
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }
}
