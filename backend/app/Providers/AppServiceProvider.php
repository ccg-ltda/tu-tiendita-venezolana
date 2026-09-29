<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use App\Repositories\CheckoutSheetsRepository;
use App\Services\{CheckoutAdminOrderStatusService,CheckoutDirectPreparationService,CheckoutExpiredReservationReleaseService,CheckoutIdSequenceStore,CheckoutJournalStore,CheckoutLock,CheckoutPaymentEventService,CheckoutPaymentEventNormalizer,CheckoutPaymentEventPlanner,CheckoutPaymentEventStateValidator,CheckoutPayloadCanonicalizer,CheckoutRecoveryService,CheckoutReferenceGenerator,CheckoutReleaseCandidateReader,CheckoutReleaseFingerprint,CheckoutReleaseJournalStore,CheckoutReservationPlanner,CheckoutReservationWriter,CheckoutUtcTimestamp,GoogleSheetsClient};

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CheckoutSheetsRepository::class,fn()=>new CheckoutSheetsRepository(new GoogleSheetsClient));
        $this->app->singleton(CheckoutDirectPreparationService::class,function($app){$repo=$app->make(CheckoutSheetsRepository::class);$base=storage_path('app/private/checkout');$lock=new CheckoutLock;return new CheckoutDirectPreparationService($lock,new CheckoutPayloadCanonicalizer,new CheckoutJournalStore($base.DIRECTORY_SEPARATOR.'journal'),new CheckoutRecoveryService($lock,new CheckoutJournalStore($base.DIRECTORY_SEPARATOR.'journal'),$repo),new CheckoutReservationPlanner($repo,new CheckoutPayloadCanonicalizer,new CheckoutReferenceGenerator,new CheckoutIdSequenceStore($base.DIRECTORY_SEPARATOR.'sequences.json')),new CheckoutReservationWriter($lock,new CheckoutJournalStore($base.DIRECTORY_SEPARATOR.'journal'),$repo),$repo);});
        $this->app->singleton(CheckoutPaymentEventService::class,fn($app)=>new CheckoutPaymentEventService(new CheckoutLock,$app->make(CheckoutSheetsRepository::class),new CheckoutIdSequenceStore(storage_path('app/private/checkout/sequences.json')),new CheckoutPaymentEventNormalizer,new CheckoutPaymentEventStateValidator,new CheckoutPaymentEventPlanner));
        $this->app->singleton(CheckoutReleaseCandidateReader::class,fn($app)=>new CheckoutReleaseCandidateReader($app->make(CheckoutSheetsRepository::class),new CheckoutUtcTimestamp));
        $this->app->singleton(CheckoutExpiredReservationReleaseService::class,fn($app)=>new CheckoutExpiredReservationReleaseService(new CheckoutLock,$app->make(CheckoutSheetsRepository::class),new CheckoutReleaseJournalStore(storage_path('app/private/checkout/release-journal')),new CheckoutReleaseFingerprint,new CheckoutUtcTimestamp));
        $this->app->singleton(CheckoutAdminOrderStatusService::class,fn($app)=>new CheckoutAdminOrderStatusService(new CheckoutLock,$app->make(CheckoutSheetsRepository::class),new CheckoutUtcTimestamp));
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
