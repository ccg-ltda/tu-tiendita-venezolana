<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProductPromotionRequest;
use App\Services\ProductPromotionAdminService;
use App\Services\AdminAuditService;
use App\Services\PersistenceException;
use Illuminate\Http\JsonResponse;

final class ProductPromotionController extends Controller
{
    public function index(ProductPromotionAdminService $promotions): JsonResponse
    {
        try {
            return response()->json(['promotions' => $promotions->list()]);
        } catch (PersistenceException $exception) {
            return $this->error($exception);
        }
    }

    public function show(int $productId, ProductPromotionAdminService $promotions): JsonResponse
    {
        try {
            return response()->json($promotions->show($productId));
        } catch (PersistenceException $exception) {
            return $this->error($exception);
        }
    }

    public function update(int $productId, UpdateProductPromotionRequest $request, ProductPromotionAdminService $promotions, AdminAuditService $audit): JsonResponse
    {
        try {
            $before = $promotions->show($productId);
            $saved = $promotions->save($productId, $request->validated());
            $previous = $before['promotion'] ?? null;
            $current = $saved['promotion'] ?? [];
            $action = $previous === null ? 'CREATE' : (($previous['active'] ?? null) === ($current['active'] ?? null) ? 'UPDATE' : ($current['active'] ? 'ACTIVATE' : 'DEACTIVATE'));
            $audit->record($request, $action, 'PROMOTION', $productId, is_array($previous) ? $previous : [], is_array($current) ? $current : [], $saved['product_name'] ?? null);
            return response()->json($saved);
        } catch (PersistenceException $exception) {
            return $this->error($exception);
        }
    }

    private function error(PersistenceException $exception): JsonResponse
    {
        return response()->json(['message' => $exception->getMessage()], $exception->status());
    }
}
