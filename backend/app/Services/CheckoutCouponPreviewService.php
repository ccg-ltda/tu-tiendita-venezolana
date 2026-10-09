<?php

namespace App\Services;

use App\Exceptions\CheckoutReservationPlanningException;
use App\Promotions\ProductPromotionPriceResolver;
use App\Promotions\PromotionContractException;
use App\Repositories\MySqlProductRepository;
use App\Repositories\MySqlProductPromotionRepository;
use DateTimeInterface;

/** Read-only current-cart pricing and coupon evaluation for the public checkout. */
final class CheckoutCouponPreviewService
{
    public function __construct(
        private readonly MySqlProductRepository $products,
        private readonly MySqlProductPromotionRepository $promotions,
        private readonly ProductPromotionPriceResolver $prices,
        private readonly CheckoutPayloadCanonicalizer $canonicalizer,
        private readonly SqlCouponPlanner $coupons,
    ) {}

    /** @param list<array{id:int|string,qty:int|string}> $items */
    public function preview(string $code, array $items, DateTimeInterface $now): array
    {
        try { $items = $this->canonicalizer->normalizeItems($items); }
        catch (\InvalidArgumentException) { throw new CheckoutReservationPlanningException('INVALID_ITEMS'); }

        try {
            $byProduct = collect($this->products->all())->keyBy('product_id')->all();
            $promotions = $this->promotions->byProductIds(array_column($items, 'product_id'));
        } catch (\Throwable) {
            throw new CheckoutReservationPlanningException('CATALOG_UNAVAILABLE');
        }

        $subtotal = 0;
        $eligibleSubtotal = 0;
        foreach ($items as $item) {
            $product = $byProduct[$item['product_id']] ?? null;
            if ($product === null) throw new CheckoutReservationPlanningException('PRODUCT_NOT_FOUND');
            if (! $product['active']) throw new CheckoutReservationPlanningException('PRODUCT_INACTIVE');
            if (($product['category_active'] ?? true) !== true || ($product['subcategory_active'] ?? true) !== true) {
                throw new CheckoutReservationPlanningException('PRODUCT_INACTIVE');
            }
            if ($product['price_cop'] < 1) throw new CheckoutReservationPlanningException('INVALID_PRODUCT_PRICE');
            if ($product['inventory'] < $item['quantity']) throw new CheckoutReservationPlanningException('INSUFFICIENT_STOCK');
            $pricing = $this->effectivePrice($product['price_cop'], $promotions[$product['product_id']] ?? null, $now);
            $lineTotal = $pricing['effective_price_cop'] * $item['quantity'];
            if ($lineTotal > PHP_INT_MAX - $subtotal) throw new CheckoutReservationPlanningException('INVALID_ITEMS');
            $subtotal += $lineTotal;
            if (! $pricing['has_active_promotion']) $eligibleSubtotal += $lineTotal;
        }

        $evaluation = $this->coupons->evaluate($code, $eligibleSubtotal, $now);
        $discount = $evaluation['discount_cop'];
        return [
            'ok' => true,
            'coupon' => ['code' => $evaluation['coupon']['code'], 'discount_type' => $evaluation['coupon']['discount_type'], 'discount_value' => $evaluation['coupon']['discount_value']],
            'subtotal_cop' => $subtotal,
            'eligible_subtotal_cop' => $eligibleSubtotal,
            'coupon_discount_cop' => $discount,
            'total_cop' => $subtotal - $discount,
            'excluded_promotional_subtotal_cop' => $subtotal - $eligibleSubtotal,
        ];
    }

    /** Mirrors checkout planning: invalid promotion contracts are never ignored merely because they are inactive today. */
    private function effectivePrice(int $basePriceCop, ?array $promotion, DateTimeInterface $now): array
    {
        try {
            if ($promotion !== null) {
                $this->prices->resolve($basePriceCop, array_replace($promotion, ['active' => true, 'starts_at' => null, 'ends_at' => null]), $now);
            }
            return $this->prices->resolve($basePriceCop, $promotion, $now);
        } catch (PromotionContractException) {
            throw new CheckoutReservationPlanningException('INVALID_PROMOTION_CONTRACT');
        }
    }
}
