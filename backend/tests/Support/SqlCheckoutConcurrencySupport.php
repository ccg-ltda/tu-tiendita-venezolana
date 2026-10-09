<?php

namespace Tests\Support;

use App\Coupons\CouponDiscountResolver;
use App\Promotions\ProductPromotionPriceResolver;
use App\Repositories\MySqlCouponRepository;
use App\Repositories\MySqlProductPromotionRepository;
use App\Services\CheckoutPayloadCanonicalizer;
use App\Services\CheckoutPaymentEventNormalizer;
use App\Services\CheckoutReferenceGenerator;
use App\Services\OrderNotificationOutboxStore;
use App\Services\SqlCheckoutService;
use App\Services\SqlCouponPlanner;

final class SqlCheckoutConcurrencySupport
{
    public static function service(): SqlCheckoutService
    {
        return new SqlCheckoutService(
            new CheckoutPayloadCanonicalizer, new CheckoutReferenceGenerator,
            new MySqlProductPromotionRepository, new ProductPromotionPriceResolver,
            new CheckoutPaymentEventNormalizer,
            new SqlCouponPlanner(new MySqlCouponRepository, new CouponDiscountResolver),
            new OrderNotificationOutboxStore,
        );
    }
}
