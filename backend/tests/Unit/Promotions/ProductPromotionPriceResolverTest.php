<?php

namespace Tests\Unit\Promotions;

use App\Promotions\ProductPromotionNormalizer;
use App\Promotions\ProductPromotionPriceResolver;
use App\Promotions\PromotionContractException;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductPromotionPriceResolverTest extends TestCase
{
    private ProductPromotionPriceResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ProductPromotionPriceResolver;
    }

    public function test_it_resolves_a_valid_percentage_discount(): void
    {
        $result = $this->resolve(20000, $this->promotion('percent', 15));

        $this->assertSame(['has_active_promotion' => true, 'base_price_cop' => 20000, 'effective_price_cop' => 17000, 'discount_cop' => 3000, 'discount_type' => 'percent', 'discount_value' => 15], $result);
    }

    public function test_it_resolves_a_valid_fixed_discount(): void
    {
        $result = $this->resolve(20000, $this->promotion('fixed', 5000));

        $this->assertSame(15000, $result['effective_price_cop']);
        $this->assertSame(5000, $result['discount_cop']);
    }

    #[DataProvider('invalidPercentageValues')]
    public function test_it_rejects_zero_and_one_hundred_percent(int $value): void
    {
        $this->expectException(PromotionContractException::class);
        $this->resolve(20000, $this->promotion('percent', $value));
    }

    public static function invalidPercentageValues(): array
    {
        return [[0], [100]];
    }

    public function test_it_rejects_a_zero_fixed_discount(): void
    {
        $this->expectException(PromotionContractException::class);
        $this->resolve(20000, $this->promotion('fixed', 0));
    }

    #[DataProvider('fixedDiscountsThatDoNotLeaveAPositivePrice')]
    public function test_it_rejects_fixed_discounts_equal_to_or_greater_than_the_base_price(int $discount): void
    {
        $this->expectException(PromotionContractException::class);
        $this->resolve(20000, $this->promotion('fixed', $discount));
    }

    public static function fixedDiscountsThatDoNotLeaveAPositivePrice(): array
    {
        return [[20000], [20001]];
    }

    public function test_an_inactive_promotion_does_not_apply(): void
    {
        $result = $this->resolve(20000, $this->promotion('percent', 15, ['active' => false]));

        $this->assertSame($this->noPromotion(20000), $result);
    }

    public function test_a_promotion_without_dates_applies_indefinitely(): void
    {
        $result = $this->resolve(20000, $this->promotion('percent', 15));

        $this->assertTrue($result['has_active_promotion']);
    }

    public function test_a_future_start_date_does_not_apply(): void
    {
        $result = $this->resolve(20000, $this->promotion('percent', 15, ['starts_at' => '2026-10-07T00:00:00.000-05:00']));

        $this->assertSame($this->noPromotion(20000), $result);
    }

    public function test_a_promotion_with_a_current_window_applies(): void
    {
        $result = $this->resolve(20000, $this->promotion('percent', 15, ['starts_at' => '2026-10-05T00:00:00.000-05:00', 'ends_at' => '2026-10-07T00:00:00.000-05:00']));

        $this->assertTrue($result['has_active_promotion']);
    }

    public function test_an_expired_promotion_does_not_apply(): void
    {
        $result = $this->resolve(20000, $this->promotion('percent', 15, ['ends_at' => '2026-10-05T00:00:00.000-05:00']));

        $this->assertSame($this->noPromotion(20000), $result);
    }

    public function test_a_promotion_with_only_a_start_date_applies_from_that_date(): void
    {
        $result = $this->resolve(20000, $this->promotion('percent', 15, ['starts_at' => '2026-10-01T00:00:00.000-05:00']));

        $this->assertTrue($result['has_active_promotion']);
    }

    public function test_a_promotion_with_only_an_end_date_applies_until_that_date(): void
    {
        $result = $this->resolve(20000, $this->promotion('percent', 15, ['ends_at' => '2026-10-07T00:00:00.000-05:00']));

        $this->assertTrue($result['has_active_promotion']);
    }

    public function test_it_rejects_an_invalid_date_range(): void
    {
        $this->expectException(PromotionContractException::class);
        ProductPromotionNormalizer::normalize($this->promotion('percent', 15, ['starts_at' => '2026-10-08T00:00:00.000-05:00', 'ends_at' => '2026-10-07T00:00:00.000-05:00']));
    }

    public function test_it_rejects_an_invalid_base_price(): void
    {
        $this->expectException(PromotionContractException::class);
        $this->resolve(0, null);
    }

    public function test_a_product_without_a_promotion_keeps_its_base_price(): void
    {
        $this->assertSame($this->noPromotion(20000), $this->resolve(20000, null));
    }

    /** @param array<string,mixed> $overrides */
    private function promotion(string $type, int $value, array $overrides = []): array
    {
        return array_replace([
            'product_id' => 7,
            'active' => true,
            'discount_type' => $type,
            'discount_value' => $value,
            'starts_at' => null,
            'ends_at' => null,
            'updated_at' => '2026-10-06T12:00:00.000Z',
            'revision' => 1,
        ], $overrides);
    }

    /** @param array<string,mixed>|null $promotion */
    private function resolve(int $basePriceCop, ?array $promotion): array
    {
        return $this->resolver->resolve($basePriceCop, $promotion, new DateTimeImmutable('2026-10-06T12:00:00.000-05:00'));
    }

    private function noPromotion(int $basePriceCop): array
    {
        return ['has_active_promotion' => false, 'base_price_cop' => $basePriceCop, 'effective_price_cop' => $basePriceCop, 'discount_cop' => 0];
    }
}
