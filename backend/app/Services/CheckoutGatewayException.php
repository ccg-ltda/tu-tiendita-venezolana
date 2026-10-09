<?php

namespace App\Services;

final class CheckoutGatewayException extends \RuntimeException
{
    public function __construct(
        private readonly int $httpStatus,
        private readonly ?string $checkoutCode = null,
    ) {
        parent::__construct($checkoutCode ?? 'CHECKOUT_UNAVAILABLE');
    }

    public function status(): int
    {
        return $this->httpStatus;
    }

    public function remoteCode(): ?string
    {
        return $this->checkoutCode;
    }

    public function ambiguousPrepareFailure(): bool
    {
        return false;
    }
}
