<?php

namespace App\Repositories;

use App\Models\Coupon;
use App\Services\PersistenceException;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;

/** MySQL adapter that preserves the established coupon API contract. */
final class MySqlCouponRepository
{
    private const BOGOTA = 'America/Bogota';

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return Coupon::query()->orderBy('id')->get()->map(fn (Coupon $coupon): array => $this->record($coupon))->all();
    }

    /** @return array<string,mixed>|null */
    public function findByCouponId(int $id): ?array
    {
        $coupon = Coupon::query()->find($id);
        return $coupon === null ? null : $this->record($coupon);
    }

    /** @return array<string,mixed>|null */
    public function findByCode(string $code): ?array
    {
        $coupon = Coupon::query()->where('code', $code)->first();
        return $coupon === null ? null : $this->record($coupon);
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    public function create(array $candidate): array
    {
        try {
            $coupon = Coupon::query()->create($this->attributes($candidate, 1));
        } catch (QueryException $exception) {
            if ($this->isDuplicate($exception)) throw new PersistenceException(422, 'DUPLICATE_COUPON_CODE', $exception, 'El código de cupón ya existe.');
            throw $exception;
        }
        return $this->record($coupon->fresh());
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    public function update(int $id, int $expectedRevision, array $candidate): array
    {
        $affected = Coupon::query()->whereKey($id)->where('revision', $expectedRevision)
            ->update($this->attributes($candidate, $expectedRevision + 1));
        if ($affected !== 1) {
            if (Coupon::query()->whereKey($id)->doesntExist()) throw new PersistenceException(404, 'COUPON_NOT_FOUND', null, 'El cupón no existe.');
            throw $this->conflict();
        }
        return $this->findByCouponId($id) ?? throw new \LogicException('Coupon was not persisted.');
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    private function attributes(array $candidate, int $revision): array
    {
        return [
            'code' => $candidate['code'], 'description' => $candidate['description'], 'active' => $candidate['active'],
            'discount_type' => $candidate['discount_type'], 'discount_value' => $candidate['discount_value'],
            'minimum_order_cop' => $candidate['minimum_order_cop'], 'max_uses' => $candidate['max_uses'],
            'used_count' => $candidate['used_count'], 'starts_at' => $this->databaseCommercialDate($candidate['starts_at']),
            'ends_at' => $this->databaseCommercialDate($candidate['ends_at']), 'revision' => $revision,
        ];
    }

    /** @return array<string,mixed> */
    private function record(Coupon $coupon): array
    {
        return [
            'coupon_id' => $coupon->id, 'code' => $coupon->code, 'description' => $coupon->description,
            'active' => $coupon->active, 'discount_type' => $coupon->discount_type,
            'discount_value' => $coupon->discount_value, 'minimum_order_cop' => $coupon->minimum_order_cop,
            'max_uses' => $coupon->max_uses, 'used_count' => $coupon->used_count,
            'starts_at' => $this->commercialDate($coupon->getRawOriginal('starts_at')),
            'ends_at' => $this->commercialDate($coupon->getRawOriginal('ends_at')),
            'created_at' => $coupon->created_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
            'updated_at' => $coupon->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'), 'revision' => $coupon->revision,
        ];
    }

    private function databaseCommercialDate(?string $value): ?string { return $value === null ? null : (new DateTimeImmutable($value))->setTimezone(new DateTimeZone(self::BOGOTA))->format('Y-m-d H:i:s.v'); }
    private function commercialDate(mixed $value): ?string { return $value === null || $value === '' ? null : (new DateTimeImmutable((string) $value, new DateTimeZone(self::BOGOTA)))->setTimezone(new DateTimeZone(self::BOGOTA))->format('Y-m-d\\TH:i:s.vP'); }
    private function conflict(): PersistenceException { return new PersistenceException(409, 'COUPON_REVISION_CONFLICT', null, 'El cupón cambió; actualiza e intenta nuevamente.'); }
    private function isDuplicate(QueryException $exception): bool { return str_contains((string) $exception->getCode(), '23000') || str_contains(strtolower($exception->getMessage()), 'duplicate'); }
}
