<?php

namespace App\Services;

final class PersistenceException extends \RuntimeException
{
    public function __construct(private readonly int $httpStatus, private readonly string $persistenceCode, ?\Throwable $previous = null, ?string $message = null)
    {
        parent::__construct($message ?? 'No fue posible completar la operación.', 0, $previous);
    }

    public function status(): int { return $this->httpStatus; }
    public function remoteCode(): string { return $this->persistenceCode; }
}
