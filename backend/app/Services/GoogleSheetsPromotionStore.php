<?php

namespace App\Services;

use App\Contracts\GoogleSheetsValuesClient;
use App\Promotions\ProductPromotionNormalizer;
use App\Promotions\PromotionContractException;

final class GoogleSheetsPromotionStore
{
    private const SHEET = 'Promociones';

    public function __construct(private readonly GoogleSheetsValuesClient $client) {}

    /** @return list<array{product_id:int,active:bool,discount_type:'percent'|'fixed',discount_value:int,starts_at:string|null,ends_at:string|null,updated_at:string,revision:int}> */
    public function all(): array
    {
        return array_map(static fn (array $record): array => $record['promotion'], $this->records());
    }

    /** @param array<string,mixed> $promotion @return array{product_id:int,active:bool,discount_type:'percent'|'fixed',discount_value:int,starts_at:string|null,ends_at:string|null,updated_at:string,revision:int} */
    public function save(array $promotion, ?int $expectedRevision): array
    {
        try {
            $promotion = ProductPromotionNormalizer::normalize($promotion);
        } catch (PromotionContractException $exception) {
            throw new ProductSheetsException(422, 'INVALID_PROMOTION', $exception, 'La promoción no es válida.');
        }
        $existing = null;
        foreach ($this->records() as $record) {
            if ($record['promotion']['product_id'] === $promotion['product_id']) {
                $existing = $record;
                break;
            }
        }
        if ($existing === null && $expectedRevision !== null) {
            throw new ProductSheetsException(409, 'PROMOTION_REVISION_CONFLICT', null, 'La promoción cambió; actualiza e intenta nuevamente.');
        }
        if ($existing !== null && $existing['promotion']['revision'] !== $expectedRevision) {
            throw new ProductSheetsException(409, 'PROMOTION_REVISION_CONFLICT', null, 'La promoción cambió; actualiza e intenta nuevamente.');
        }

        $next = $promotion;
        $next['revision'] = $existing === null ? 1 : $existing['promotion']['revision'] + 1;
        try {
            if ($existing === null) {
                $this->client->appendValues(self::SHEET.'!A:H', [$this->row($next)]);
            } else {
                $row = $existing['sheet_row'];
                $this->client->updateValues(self::SHEET."!A{$row}:H{$row}", [$this->row($next)]);
            }
        } catch (ProductSheetsException $exception) {
            throw new ProductSheetsException($exception->status(), 'PROMOTIONS_SHEET_WRITE_FAILED', $exception, 'No fue posible guardar la promoción.');
        }

        $saved = $this->findByProductId($next['product_id']);
        if ($saved === null || $saved !== $next) {
            throw new ProductSheetsException(502, 'PROMOTIONS_SHEET_WRITE_UNVERIFIED', null, 'No fue posible verificar la promoción guardada.');
        }

        return $saved;
    }

    /** @return list<array{sheet_row:int,promotion:array{product_id:int,active:bool,discount_type:'percent'|'fixed',discount_value:int,starts_at:string|null,ends_at:string|null,updated_at:string,revision:int}}> */
    private function records(): array
    {
        try {
            $headerRow = $this->client->getValues(self::SHEET.'!1:1');
            $values = $this->client->getValues(self::SHEET.'!A:H');
        } catch (ProductSheetsException $exception) {
            throw new ProductSheetsException($exception->status(), 'PROMOTIONS_SHEET_UNAVAILABLE', $exception, 'No fue posible consultar la hoja Promociones.');
        }
        if ($headerRow === [] || $values === []) {
            throw new ProductSheetsException(502, 'PROMOTIONS_SHEET_MISSING_OR_EMPTY', null, 'La hoja Promociones no existe o no tiene encabezados.');
        }
        $headers = $headerRow[0] ?? null;
        if ($headers !== ProductPromotionNormalizer::HEADERS) {
            throw new ProductSheetsException(502, 'INVALID_PROMOTIONS_HEADERS', null, 'Los encabezados de la hoja Promociones no coinciden con el contrato requerido.');
        }

        $promotions = [];
        $ids = [];
        foreach (array_slice($values, 1) as $index => $row) {
            if (! array_filter($row, static fn (mixed $value): bool => $value !== '' && $value !== null)) {
                continue;
            }
            try {
                $promotion = ProductPromotionNormalizer::sheetRow($row);
            } catch (PromotionContractException $exception) {
                throw new ProductSheetsException(502, 'INVALID_PROMOTIONS_DATA', $exception, 'La hoja Promociones contiene datos inválidos.');
            }
            if (isset($ids[$promotion['product_id']])) {
                throw new ProductSheetsException(502, 'DUPLICATE_PROMOTION_PRODUCT_ID', null, 'La hoja Promociones contiene más de una promoción para el mismo producto.');
            }
            $ids[$promotion['product_id']] = true;
            $promotions[] = ['sheet_row' => $index + 2, 'promotion' => $promotion];
        }

        return $promotions;
    }

    /** @return array{product_id:int,active:bool,discount_type:'percent'|'fixed',discount_value:int,starts_at:string|null,ends_at:string|null,updated_at:string,revision:int}|null */
    public function findByProductId(int $productId): ?array
    {
        foreach ($this->all() as $promotion) {
            if ($promotion['product_id'] === $productId) {
                return $promotion;
            }
        }

        return null;
    }

    /** @param array{product_id:int,active:bool,discount_type:'percent'|'fixed',discount_value:int,starts_at:string|null,ends_at:string|null,updated_at:string,revision:int} $promotion @return list<mixed> */
    private function row(array $promotion): array
    {
        return array_map(static fn (string $field): mixed => $promotion[$field], ProductPromotionNormalizer::HEADERS);
    }
}
