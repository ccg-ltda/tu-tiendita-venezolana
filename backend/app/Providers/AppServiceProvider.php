<?php

namespace App\Providers;

use App\Coupons\CouponDiscountResolver;
use App\Promotions\ProductPromotionPriceResolver;
use App\Repositories\MySqlCouponRepository;
use App\Repositories\MySqlProductPromotionRepository;
use App\Repositories\MySqlProductRepository;
use App\Services\CheckoutCouponPreviewService;
use App\Services\CheckoutPayloadCanonicalizer;
use App\Services\CheckoutPaymentEventNormalizer;
use App\Services\CheckoutReferenceGenerator;
use App\Services\CheckoutWriterGateway;
use App\Services\CouponAdminService;
use App\Services\SqlCheckoutService;
use App\Services\SqlCouponPlanner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CouponAdminService::class, fn ($app) => new CouponAdminService(
            $app->make(MySqlCouponRepository::class), new CouponDiscountResolver,
        ));
        $this->app->singleton(SqlCouponPlanner::class, fn ($app) => new SqlCouponPlanner(
            $app->make(MySqlCouponRepository::class), new CouponDiscountResolver,
        ));
        $this->app->singleton(CheckoutCouponPreviewService::class, fn ($app) => new CheckoutCouponPreviewService(
            $app->make(MySqlProductRepository::class),
            $app->make(MySqlProductPromotionRepository::class),
            new ProductPromotionPriceResolver,
            new CheckoutPayloadCanonicalizer,
            $app->make(SqlCouponPlanner::class),
        ));
        $this->app->singleton(SqlCheckoutService::class, fn ($app) => new SqlCheckoutService(
            new CheckoutPayloadCanonicalizer,
            new CheckoutReferenceGenerator,
            $app->make(MySqlProductPromotionRepository::class),
            new ProductPromotionPriceResolver,
            new CheckoutPaymentEventNormalizer,
            $app->make(SqlCouponPlanner::class),
            $app->make(\App\Services\OrderNotificationOutboxStore::class),
        ));
        $this->app->singleton(CheckoutWriterGateway::class, fn ($app) => new CheckoutWriterGateway(
            $app->make(SqlCheckoutService::class),
        ));
    }

    public function boot(): void
    {
        RateLimiter::for('admin-login', static fn (Request $request): Limit => Limit::perMinutes(15, 8)
            ->by('admin-login:'.$request->ip()));

        RateLimiter::for('wompi-prepare', static fn (Request $request): Limit => Limit::perMinute(10)
            ->by('wompi-prepare:'.$request->ip())
            ->response(static fn (Request $request, array $headers) => response()->json([
                'error' => 'Demasiados intentos de pago. Intenta nuevamente en un minuto.',
            ], 429, $headers)));

        RateLimiter::for('wompi-status', static function (Request $request): Limit {
            $token = $request->header('X-Checkout-Status-Token');

            return Limit::perMinute(20)
                ->by('wompi-status:'.$request->ip().':'.hash('sha256', is_string($token) ? $token : 'missing'));
        });
    }
}
