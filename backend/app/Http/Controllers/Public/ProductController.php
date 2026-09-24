<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\CatalogSnapshotException;
use App\Services\CatalogSnapshotStore;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    /**
     * Return the public catalog using the legacy frontend contract.
     */
    public function index(CatalogSnapshotStore $catalog): JsonResponse
    {
        try {
            $sheetProducts = $catalog->read();
        } catch (CatalogSnapshotException) {
            return $this->catalogUnavailable();
        }

        $products = array_values(array_map(
            static fn (array $product): array => [
                'id' => $product['product_id'],
                'img' => $product['legacy_img'],
                'cat' => $product['category'],
                'sub' => $product['subcategory'],
                'name' => $product['name'],
                'pres' => $product['presentation'],
                'price' => $product['price_cop'],
                'image' => $product['image_path'],
                'inventory' => $product['inventory'],
                'active' => $product['active'],
            ],
            array_filter($sheetProducts, static fn (array $product): bool => $product['active'] === true),
        ));

        usort($products, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return response()->json([
            'products' => $products,
        ]);
    }

    private function catalogUnavailable(): JsonResponse
    {
        return response()->json([
            'message' => 'El catálogo no está disponible en este momento.',
        ], 503, ['Retry-After' => '60']);
    }
}
