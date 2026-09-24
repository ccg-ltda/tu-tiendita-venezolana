<?php

use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Auth\AdminAuthController;
use App\Http\Controllers\Public\ProductController;
use App\Http\Middleware\EnsureAdminAuthenticated;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/api/products', [ProductController::class, 'index']);
Route::prefix('api/admin')
    ->middleware(EnsureAdminAuthenticated::class)
    ->group(function () {
        Route::get('/orders', [AdminOrderController::class, 'index']);
        Route::get('/orders/{order}', [AdminOrderController::class, 'show']);
        Route::patch('/orders/{order}/status', [AdminOrderController::class, 'updateStatus']);
        Route::get('/products', [AdminProductController::class, 'index']);
        Route::post('/products', [AdminProductController::class, 'store']);
        Route::patch('/products/{productId}', [AdminProductController::class, 'update']);
        Route::patch('/products/{productId}/status', [AdminProductController::class, 'updateStatus']);
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
        ->middleware('throttle:admin-login');

    // Protege las operaciones que requieren un administrador autenticado.
    Route::middleware(EnsureAdminAuthenticated::class)->group(function () {
        Route::get('/me', [AdminAuthController::class, 'me']);
        Route::post('/logout', [AdminAuthController::class, 'logout']);
    });
});
