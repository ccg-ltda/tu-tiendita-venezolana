<?php

namespace App\Repositories;

use App\Models\ProductPromotion;
use App\Services\PersistenceException;
use DateTimeImmutable;
use DateTimeZone;

/** MySQL persistence adapter preserving the promotion API contract. */
class MySqlProductPromotionRepository
{
    private const BOGOTA = 'America/Bogota';

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return ProductPromotion::query()->orderBy('product_id')->get()->map(fn (ProductPromotion $promotion): array => $this->record($promotion))->all();
    }

    /** @param list<int> $productIds @return array<int,array<string,mixed>> */
    public function byProductIds(array $productIds): array
    {
        if ($productIds === []) return [];
        return ProductPromotion::query()->whereIn('product_id', array_values(array_unique($productIds)))->get()->mapWithKeys(fn (ProductPromotion $promotion): array => [$promotion->product_id => $this->record($promotion)])->all();
    }

    /** @return array<string,mixed>|null */
    public function findByProductId(int $productId): ?array
    {
        $promotion = ProductPromotion::query()->find($productId);
        return $promotion === null ? null : $this->record($promotion);
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    public function save(array $candidate, ?int $expectedRevision): array
    {
        $id = $candidate['product_id'];
        $existing = ProductPromotion::query()->find($id);
        if ($existing === null) {
            if ($expectedRevision !== null) throw $this->conflict();
            ProductPromotion::query()->create($this->attributes($candidate, 1));
            return $this->findByProductId($id) ?? throw new \LogicException('Promotion was not persisted.');
        }
        if ($expectedRevision === null || $existing->revision !== $expectedRevision) throw $this->conflict();
        $nextRevision = $expectedRevision + 1;
        $affected = ProductPromotion::query()->where('product_id', $id)->where('revision', $expectedRevision)->update($this->attributes($candidate, $nextRevision));
        if ($affected !== 1) throw $this->conflict();
        return $this->findByProductId($id) ?? throw new \LogicException('Promotion was not persisted.');
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    private function attributes(array $candidate, int $revision): array
    {
        return ['product_id' => $candidate['product_id'], 'active' => $candidate['active'], 'discount_type' => $candidate['discount_type'], 'discount_value' => $candidate['discount_value'], 'starts_at' => $this->databaseCommercialDate($candidate['starts_at']), 'ends_at' => $this->databaseCommercialDate($candidate['ends_at']), 'revision' => $revision];
    }

    /** @return array<string,mixed> */
    private function record(ProductPromotion $promotion): array
    {
        return ['product_id' => $promotion->product_id, 'active' => $promotion->active, 'discount_type' => $promotion->discount_type, 'discount_value' => $promotion->discount_value, 'starts_at' => $this->commercialDate($promotion->getRawOriginal('starts_at')), 'ends_at' => $this->commercialDate($promotion->getRawOriginal('ends_at')), 'updated_at' => $promotion->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'), 'revision' => $promotion->revision];
    }

    private function databaseCommercialDate(?string $value): ?string
    {
        return $value === null ? null : (new DateTimeImmutable($value))->setTimezone(new DateTimeZone(self::BOGOTA))->format('Y-m-d H:i:s.v');
    }

    private function commercialDate(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        return (new DateTimeImmutable((string) $value, new DateTimeZone(self::BOGOTA)))->setTimezone(new DateTimeZone(self::BOGOTA))->format('Y-m-d\\TH:i:s.vP');
    }

    private function conflict(): PersistenceException { return new PersistenceException(409, 'PROMOTION_REVISION_CONFLICT', null, 'La promoción cambió; actualiza e intenta nuevamente.'); }
}
