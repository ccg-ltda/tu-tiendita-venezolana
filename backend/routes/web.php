<?php

use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\ProductPromotionController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\AdminActivityController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Auth\AdminAuthController;
use App\Http\Controllers\Public\ProductController;
use App\Http\Middleware\EnsureAdminAuthenticated;
use App\Repositories\MySqlCategoryRepository;
use App\Repositories\MySqlProductRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/api/products', [ProductController::class, 'index'])
    ->middleware('throttle:public-catalog');
Route::get('/api/products/{slug}', [ProductController::class, 'show'])
    ->where('slug', '[a-z0-9-]+')
    ->middleware('throttle:public-catalog');

Route::get('/sitemap.xml', function (MySqlProductRepository $products, MySqlCategoryRepository $categories) {
    $data = Cache::remember('sitemap-xml-data', 3600, function () use ($products, $categories): array {
        $activeProducts = array_values(array_filter(
            $products->all(),
            static fn (array $p): bool => $p['active'] === true && ($p['category_active'] ?? true) === true && ($p['subcategory_active'] ?? true) === true,
        ));
        $activeCategories = array_values(array_filter($categories->all(), static fn (array $c): bool => $c['active'] === true));

        return ['products' => $activeProducts, 'categories' => $activeCategories];
    });

    return response()
        ->view('sitemap', $data)
        ->header('Content-Type', 'application/xml');
})->middleware('throttle:public-catalog');
Route::prefix('api/admin')
    ->middleware(EnsureAdminAuthenticated::class)
    ->group(function () {
        Route::get('/audit/administrators', [AdminActivityController::class, 'administrators']);
        Route::get('/audit', [AdminActivityController::class, 'index']);
        Route::get('/orders', [AdminOrderController::class, 'index']);
        Route::get('/orders/{order}', [AdminOrderController::class, 'show']);
        Route::patch('/orders/{order}/status', [AdminOrderController::class, 'updateStatus']);
        Route::get('/products', [AdminProductController::class, 'index']);
        Route::post('/products', [AdminProductController::class, 'store']);
        Route::patch('/products/{productId}', [AdminProductController::class, 'update']);
        Route::patch('/products/{productId}/status', [AdminProductController::class, 'updateStatus']);
        Route::get('/categories', [CategoryController::class, 'index']);
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::patch('/categories/{categoryId}', [CategoryController::class, 'update']);
        Route::patch('/categories/{categoryId}/status', [CategoryController::class, 'status']);
        Route::post('/categories/{categoryId}/subcategories', [CategoryController::class, 'storeSubcategory']);
        Route::patch('/subcategories/{subcategoryId}', [CategoryController::class, 'updateSubcategory']);
        Route::patch('/subcategories/{subcategoryId}/status', [CategoryController::class, 'subcategoryStatus']);
        Route::get('/promotions', [ProductPromotionController::class, 'index']);
        Route::get('/coupons', [CouponController::class, 'index']);
        Route::post('/coupons', [CouponController::class, 'store']);
        Route::get('/coupons/{couponId}', [CouponController::class, 'show']);
        Route::patch('/coupons/{couponId}', [CouponController::class, 'update']);
        Route::get('/products/{productId}/promotion', [ProductPromotionController::class, 'show']);
        Route::patch('/products/{productId}/promotion', [ProductPromotionController::class, 'update']);
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
