<?php

namespace App\Services;

use App\Coupons\CouponContractException;
use App\Coupons\CouponDiscountResolver;
use App\Coupons\CouponNormalizer;
use App\Exceptions\CheckoutReservationPlanningException;
use App\Models\Coupon;
use App\Models\CouponReservation;
use App\Repositories\MySqlCouponRepository;
use DateTimeInterface;

/** SQL-only coupon evaluation and reservation policy used by the SQL checkout. */
final class SqlCouponPlanner
{
    public function __construct(private readonly MySqlCouponRepository $coupons, private readonly CouponDiscountResolver $discounts) {}

    /** @return array{coupon:array<string,mixed>,discount_cop:int}|null */
    public function evaluate(?string $code, int $eligibleSubtotal, DateTimeInterface $now): ?array
    {
        if ($code === null || trim($code) === '') return null;
        $normalized = CouponNormalizer::normalizeCode($code);
        $coupon = $this->coupons->findByCode($normalized);
        if ($coupon === null) throw new CheckoutReservationPlanningException('COUPON_NOT_FOUND');
        return $this->evaluateCoupon($coupon, $eligibleSubtotal, $now, $coupon['max_uses'] !== null && $coupon['used_count'] < $coupon['max_uses'] ? $this->activeReservations($coupon['coupon_id'], $now) : 0);
    }

    /** Acquire the coupon lock before product locks during checkout preparation. */
    public function lock(?string $code): void
    {
        if ($code === null || trim($code) === '') return;
        $normalized = CouponNormalizer::normalizeCode($code);
        if (Coupon::query()->where('code', $normalized)->lockForUpdate()->first() === null) throw new CheckoutReservationPlanningException('COUPON_NOT_FOUND');
    }

    /** The caller must already hold the coupon row lock. @return array{coupon:array<string,mixed>,discount_cop:int}|null */
    public function evaluateLocked(?string $code, int $eligibleSubtotal, DateTimeInterface $now): ?array
    {
        if ($code === null || trim($code) === '') return null;
        $normalized = CouponNormalizer::normalizeCode($code);
        $model = Coupon::query()->where('code', $normalized)->lockForUpdate()->first();
        if ($model === null) throw new CheckoutReservationPlanningException('COUPON_NOT_FOUND');
        $coupon = $this->record($model);
        return $this->evaluateCoupon($coupon, $eligibleSubtotal, $now, $coupon['max_uses'] === null ? 0 : $this->activeReservations($model->id, $now));
    }

    /** @param array<string,mixed> $coupon @return array{coupon:array<string,mixed>,discount_cop:int} */
    private function evaluateCoupon(array $coupon, int $eligibleSubtotal, DateTimeInterface $now, int $reserved): array
    {
        try { $resolved = $this->discounts->resolve($coupon, $eligibleSubtotal, $now); }
        catch (CouponContractException) { throw new CheckoutReservationPlanningException('INVALID_COUPON'); }
        if ($coupon['max_uses'] !== null && $coupon['used_count'] + $reserved >= $coupon['max_uses']) throw new CheckoutReservationPlanningException('COUPON_EXHAUSTED');
        if ($resolved['coupon_status'] !== 'ACTIVE') throw new CheckoutReservationPlanningException('COUPON_'.$resolved['coupon_status']);
        if ($eligibleSubtotal === 0) throw new CheckoutReservationPlanningException('COUPON_NO_ELIGIBLE_ITEMS');
        if ($coupon['minimum_order_cop'] !== null && $eligibleSubtotal < $coupon['minimum_order_cop']) throw new CheckoutReservationPlanningException('COUPON_MINIMUM_NOT_MET');
        if (! $resolved['is_applicable']) throw new CheckoutReservationPlanningException('COUPON_NOT_APPLICABLE');
        return ['coupon' => $coupon, 'discount_cop' => $resolved['discount_cop']];
    }

    private function activeReservations(int $couponId, DateTimeInterface $now): int
    {
        return CouponReservation::query()->where('coupon_id', $couponId)->where('state', 'RESERVED')->where('reservation_expires_at', '>', $now)->count();
    }

    /** @return array<string,mixed> */
    private function record(Coupon $coupon): array
    {
        return ['coupon_id' => $coupon->id, 'code' => $coupon->code, 'description' => $coupon->description, 'active' => $coupon->active, 'discount_type' => $coupon->discount_type, 'discount_value' => $coupon->discount_value, 'minimum_order_cop' => $coupon->minimum_order_cop, 'max_uses' => $coupon->max_uses, 'used_count' => $coupon->used_count, 'starts_at' => $coupon->starts_at?->setTimezone('America/Bogota')->format('Y-m-d\\TH:i:s.vP'), 'ends_at' => $coupon->ends_at?->setTimezone('America/Bogota')->format('Y-m-d\\TH:i:s.vP'), 'created_at' => $coupon->created_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'), 'updated_at' => $coupon->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'), 'revision' => $coupon->revision];
    }
}
