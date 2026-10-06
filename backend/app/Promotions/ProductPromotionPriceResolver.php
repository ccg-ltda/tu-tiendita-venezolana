<?php

namespace App\Promotions;

use DateTimeInterface;
use DateTimeZone;

final class ProductPromotionPriceResolver
{
    private const BOGOTA = 'America/Bogota';

    /**
     * @param array<string,mixed>|null $promotion
     * @return array{has_active_promotion:bool,base_price_cop:int,effective_price_cop:int,discount_cop:int,discount_type?:'percent'|'fixed',discount_value?:int}
     */
    public function resolve(int $basePriceCop, ?array $promotion, DateTimeInterface $now): array
    {
        if ($basePriceCop < 1 || $basePriceCop > 2147483647) {
            throw new PromotionContractException('Base product price must be between 1 and 2147483647 COP.');
        }
        $base = $this->baseResult($basePriceCop);
        if ($promotion === null) {
            return $base;
        }

        $normalized = ProductPromotionNormalizer::normalize($promotion);
        $bogotaNow = \DateTimeImmutable::createFromInterface($now)->setTimezone(new DateTimeZone(self::BOGOTA));
        if (! $normalized['active'] || ! $this->isCurrent($normalized, $bogotaNow)) {
            return $base;
        }

        $discount = $normalized['discount_type'] === 'percent'
            ? intdiv($basePriceCop * $normalized['discount_value'], 100)
            : $normalized['discount_value'];
        $effective = $basePriceCop - $discount;
        if ($effective < 1) {
            throw new PromotionContractException('Promotion does not produce a valid effective price.');
        }

        return [
            'has_active_promotion' => true,
            'base_price_cop' => $basePriceCop,
            'effective_price_cop' => $effective,
            'discount_cop' => $discount,
            'discount_type' => $normalized['discount_type'],
            'discount_value' => $normalized['discount_value'],
        ];
    }

    /** @param array{starts_at:string|null,ends_at:string|null} $promotion */
    private function isCurrent(array $promotion, \DateTimeImmutable $now): bool
    {
        $startsAt = $promotion['starts_at'] === null ? null : new \DateTimeImmutable($promotion['starts_at']);
        $endsAt = $promotion['ends_at'] === null ? null : new \DateTimeImmutable($promotion['ends_at']);

        return ($startsAt === null || $now >= $startsAt) && ($endsAt === null || $now <= $endsAt);
    }

    /** @return array{has_active_promotion:false,base_price_cop:int,effective_price_cop:int,discount_cop:0} */
    private function baseResult(int $basePriceCop): array
    {
        return ['has_active_promotion' => false, 'base_price_cop' => $basePriceCop, 'effective_price_cop' => $basePriceCop, 'discount_cop' => 0];
    }
}
