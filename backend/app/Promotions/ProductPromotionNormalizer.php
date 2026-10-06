<?php

namespace App\Promotions;

use DateTimeImmutable;
use DateTimeZone;

final class ProductPromotionNormalizer
{
    public const HEADERS = [
        'product_id', 'active', 'discount_type', 'discount_value',
        'starts_at', 'ends_at', 'updated_at', 'revision',
    ];

    private const BOGOTA = 'America/Bogota';

    /** @return array{product_id:int,active:bool,discount_type:'percent'|'fixed',discount_value:int,starts_at:string|null,ends_at:string|null,updated_at:string,revision:int} */
    public static function normalize(mixed $promotion): array
    {
        if (! is_array($promotion)) {
            throw new PromotionContractException('Promotion must be an array.');
        }

        $productId = self::integer($promotion['product_id'] ?? null, 1);
        $active = self::boolean($promotion['active'] ?? null);
        $type = $promotion['discount_type'] ?? null;
        $value = self::integer($promotion['discount_value'] ?? null, 1);
        $startsAt = self::commercialDate($promotion['starts_at'] ?? null, 'starts_at');
        $endsAt = self::commercialDate($promotion['ends_at'] ?? null, 'ends_at');
        $updatedAt = self::auditTimestamp($promotion['updated_at'] ?? null);
        $revision = self::integer($promotion['revision'] ?? null, 1);

        if ($productId === null || $active === null || ! in_array($type, ['percent', 'fixed'], true)
            || $value === null || $updatedAt === null || $revision === null) {
            throw new PromotionContractException('Promotion contract is invalid.');
        }
        if ($type === 'percent' && $value > 99) {
            throw new PromotionContractException('Percentage discount must be between 1 and 99.');
        }
        if ($startsAt !== null && $endsAt !== null && $startsAt > $endsAt) {
            throw new PromotionContractException('Promotion start must not be after its end.');
        }

        return [
            'product_id' => $productId,
            'active' => $active,
            'discount_type' => $type,
            'discount_value' => $value,
            'starts_at' => $startsAt?->format('Y-m-d\\TH:i:s.vP'),
            'ends_at' => $endsAt?->format('Y-m-d\\TH:i:s.vP'),
            'updated_at' => $updatedAt->format('Y-m-d\\TH:i:s.v\\Z'),
            'revision' => $revision,
        ];
    }

    /** @param list<mixed> $row */
    public static function sheetRow(array $row): array
    {
        $row = array_pad($row, count(self::HEADERS), null);

        return self::normalize(array_combine(self::HEADERS, array_slice($row, 0, count(self::HEADERS))) ?: []);
    }

    private static function integer(mixed $value, int $minimum): ?int
    {
        if (! is_int($value) && (! is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1)) {
            return null;
        }
        $integer = (int) $value;

        return $integer >= $minimum && $integer <= 2147483647 ? $integer : null;
    }

    private static function boolean(mixed $value): ?bool
    {
        return match ($value) {
            true, 1, '1', 'TRUE', 'true' => true,
            false, 0, '0', 'FALSE', 'false' => false,
            default => null,
        };
    }

    private static function commercialDate(mixed $value, string $field): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}-05:00$/D', $value)) {
            throw new PromotionContractException("Promotion {$field} must be a Bogota ISO-8601 timestamp.");
        }
        try {
            $date = new DateTimeImmutable($value);
            $bogota = $date->setTimezone(new DateTimeZone(self::BOGOTA));
            if ($bogota->format('Y-m-d\\TH:i:s.vP') !== $value) {
                throw new PromotionContractException("Promotion {$field} must use America/Bogota.");
            }

            return $bogota;
        } catch (PromotionContractException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new PromotionContractException("Promotion {$field} is invalid.");
        }
    }

    private static function auditTimestamp(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $value)) {
            return null;
        }
        try {
            $date = new DateTimeImmutable($value);

            return $date->format('Y-m-d\\TH:i:s.v\\Z') === $value ? $date : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
