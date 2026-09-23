<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('admin-login', static function (Request $request): Limit {
            return Limit::perMinutes(15, 8)
                ->by('admin-login:'.$request->ip());
        });

        RateLimiter::for('wompi-prepare', static function (Request $request): Limit {
            return Limit::perMinute(10)
                ->by('wompi-prepare:'.$request->ip())
                ->response(static fn (Request $request, array $headers) => response()->json([
                    'error' => 'Demasiados intentos de pago. Intenta nuevamente en un minuto.',
                ], 429, $headers));
        });

        RateLimiter::for('wompi-status', static function (Request $request): Limit {
            $token = $request->header('X-Checkout-Status-Token');
            $tokenHash = hash('sha256', is_string($token) ? $token : 'missing');

            return Limit::perMinute(20)
                ->by('wompi-status:'.$request->ip().':'.$tokenHash);
        });

    }
}
