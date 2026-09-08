<?php

use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Auth\AdminAuthController;
use App\Http\Controllers\Public\OrderController;
use App\Http\Controllers\Public\ProductController;
use App\Http\Middleware\EnsureAdminAuthenticated;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/api/products', [ProductController::class, 'index']);
Route::post('/api/orders', [OrderController::class, 'store']);

Route::prefix('api/admin')
    ->middleware(EnsureAdminAuthenticated::class)
    ->group(function () {
        Route::get('/products', [AdminProductController::class, 'index']);
    });

Route::prefix('api/auth')->group(function () {

    // Entrega el token CSRF asociado a la sesión actual.
    Route::get('/csrf-token', function () {
        return response()->json([
            'csrf_token' => csrf_token(),
        ]);
    });

    // Limita los intentos de inicio de sesión para reducir ataques por fuerza bruta.
    Route::post('/login', [AdminAuthController::class, 'login'])
        ->middleware('throttle:8,15');

    Route::post('/forgot-password', [AdminAuthController::class, 'forgotPassword'])
        ->middleware('throttle:5,15');

    Route::post('/reset-password', [AdminAuthController::class, 'resetPassword'])
        ->middleware('throttle:5,15');

    // Protege las operaciones que requieren un administrador autenticado.
    Route::middleware(EnsureAdminAuthenticated::class)->group(function () {
        Route::get('/me', [AdminAuthController::class, 'me']);
        Route::post('/logout', [AdminAuthController::class, 'logout']);
    });
});
