<?php

namespace App\Coupons;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class CouponDiscountResolver
{
    private const BOGOTA = 'America/Bogota';

    /** @param array<string,mixed> $coupon @return array{coupon_status:string,eligible_subtotal_cop:int,discount_cop:int,total_after_coupon_cop:int,discount_type:string,discount_value:int,minimum_order_cop:int|null,is_applicable:bool} */
    public function resolve(array $coupon, int $eligibleSubtotalCop, DateTimeInterface $now): array
    {
        if ($eligibleSubtotalCop < 0 || $eligibleSubtotalCop > 2147483647) throw new CouponContractException('Eligible subtotal must be a valid COP amount.');
        $coupon=CouponNormalizer::normalize($coupon); $status=$this->status($coupon,$now);
        $base=['coupon_status'=>$status,'eligible_subtotal_cop'=>$eligibleSubtotalCop,'discount_cop'=>0,'total_after_coupon_cop'=>$eligibleSubtotalCop,'discount_type'=>$coupon['discount_type'],'discount_value'=>$coupon['discount_value'],'minimum_order_cop'=>$coupon['minimum_order_cop'],'is_applicable'=>false];
        if ($status !== 'ACTIVE' || $eligibleSubtotalCop === 0 || ($coupon['minimum_order_cop'] !== null && $eligibleSubtotalCop < $coupon['minimum_order_cop'])) return $base;
        // COP has no fractional unit: percentage discounts always round down with intdiv.
        $discount=$coupon['discount_type']==='percent' ? intdiv($eligibleSubtotalCop*$coupon['discount_value'],100) : min($coupon['discount_value'],$eligibleSubtotalCop);
        if ($discount < 1) return $base;
        return array_replace($base,['discount_cop'=>$discount,'total_after_coupon_cop'=>$eligibleSubtotalCop-$discount,'is_applicable'=>true]);
    }

    /** @param array<string,mixed> $coupon */
    public function status(array $coupon, DateTimeInterface $now): string
    {
        $coupon=CouponNormalizer::normalize($coupon); if(!$coupon['active'])return 'INACTIVE'; $now=DateTimeImmutable::createFromInterface($now)->setTimezone(new DateTimeZone(self::BOGOTA));
        if($coupon['starts_at']!==null && $now < new DateTimeImmutable($coupon['starts_at']))return 'SCHEDULED';
        if($coupon['ends_at']!==null && $now > new DateTimeImmutable($coupon['ends_at']))return 'EXPIRED';
        if($coupon['max_uses']!==null && $coupon['used_count'] >= $coupon['max_uses'])return 'EXHAUSTED';
        return 'ACTIVE';
    }
}
