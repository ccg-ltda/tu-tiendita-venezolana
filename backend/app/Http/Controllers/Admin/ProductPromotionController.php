<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProductPromotionRequest;
use App\Services\ProductPromotionAdminService;
use App\Services\ProductSheetsException;
use Illuminate\Http\JsonResponse;

final class ProductPromotionController extends Controller
{
    public function index(ProductPromotionAdminService $promotions): JsonResponse
    {
        try {
            return response()->json(['promotions' => $promotions->list()]);
        } catch (ProductSheetsException $exception) {
            return $this->error($exception);
        }
    }

    public function show(int $productId, ProductPromotionAdminService $promotions): JsonResponse
    {
        try {
            return response()->json($promotions->show($productId));
        } catch (ProductSheetsException $exception) {
            return $this->error($exception);
        }
    }

    public function update(int $productId, UpdateProductPromotionRequest $request, ProductPromotionAdminService $promotions): JsonResponse
    {
        try {
            return response()->json($promotions->save($productId, $request->validated()));
        } catch (ProductSheetsException $exception) {
            return $this->error($exception);
        }
    }

    private function error(ProductSheetsException $exception): JsonResponse
    {
        return response()->json(['message' => $exception->getMessage()], $exception->status());
    }
}
