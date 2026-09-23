<?php

use App\Http\Controllers\Public\WompiPaymentController;
use App\Http\Controllers\Public\WompiPaymentStatusController;
use App\Http\Controllers\Webhooks\WompiWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/payments/wompi/prepare', [WompiPaymentController::class, 'prepare'])
    ->middleware('throttle:wompi-prepare');

Route::get('/payments/wompi/status', [WompiPaymentStatusController::class, 'show'])
    ->middleware('throttle:wompi-status');

Route::post('/webhooks/wompi', [WompiWebhookController::class, 'handle']);
