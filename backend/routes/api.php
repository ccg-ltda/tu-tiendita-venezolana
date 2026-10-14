<?php

use App\Http\Controllers\Public\WompiPaymentController;
use App\Http\Controllers\Public\WompiPaymentStatusController;
use App\Http\Controllers\Public\CheckoutCouponPreviewController;
use App\Http\Controllers\Webhooks\WompiWebhookController;
use App\Http\Controllers\BunzyController;
use App\Http\Controllers\BlogController;
use App\Http\Middleware\EnsureWebhookPayloadSize;
use Illuminate\Support\Facades\Route;

Route::post('/payments/wompi/prepare', [WompiPaymentController::class, 'prepare'])
    ->middleware('throttle:wompi-prepare');

Route::post('/checkout/coupon/preview', [CheckoutCouponPreviewController::class, 'preview'])
    ->middleware('throttle:coupon-preview');

Route::get('/payments/wompi/status', [WompiPaymentStatusController::class, 'show'])
    ->middleware('throttle:wompi-status');

Route::post('/webhooks/wompi', [WompiWebhookController::class, 'handle'])
    ->middleware([EnsureWebhookPayloadSize::class, 'throttle:wompi-webhook']);

Route::post('/bunzy/webhook', [BunzyController::class, 'webhook'])
    ->middleware([EnsureWebhookPayloadSize::class, 'throttle:bunzy-webhook']);

Route::get('/blog', [BlogController::class, 'index'])
    ->middleware('throttle:public-blog');
Route::get('/blog/{slug}', [BlogController::class, 'show'])
    ->middleware('throttle:public-blog');
