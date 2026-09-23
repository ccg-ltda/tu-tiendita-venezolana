<?php

namespace App\Services;

use RuntimeException;

class ProductSheetsException extends RuntimeException
{
    public function __construct(private readonly int $status, private readonly ?string $remoteCode = null, ?\Throwable $previous = null, ?string $message = null)
    {
        parent::__construct($message ?? (str_contains((string) $remoteCode, 'WRITE') ? 'No fue posible sincronizar el producto con Google Sheets.' : 'No fue posible consultar el catalogo de productos.'), 0, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function remoteCode(): ?string { return $this->remoteCode; }
}
