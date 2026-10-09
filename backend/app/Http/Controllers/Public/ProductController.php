<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Promotions\ProductPromotionPriceResolver;
use App\Promotions\PromotionContractException;
use App\Repositories\MySqlProductRepository;
use App\Repositories\MySqlProductPromotionRepository;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    /**
     * Return the public catalog using the legacy frontend contract.
     */
    public function index(MySqlProductRepository $products, MySqlProductPromotionRepository $promotions, ProductPromotionPriceResolver $prices): JsonResponse
    {
        try {
            $catalogProducts = $products->all();
            $promotionsByProduct = $promotions->byProductIds(array_column($catalogProducts, 'product_id'));
        } catch (\Throwable) {
            return $this->catalogUnavailable();
        }

        try {
            $products = array_values(array_map(
                fn (array $product): array => $this->publicProduct(array_replace($product, isset($promotionsByProduct[$product['product_id']]) ? ['promotion' => $promotionsByProduct[$product['product_id']]] : []), $prices),
                array_filter($catalogProducts, static fn (array $product): bool => $product['active'] === true && ($product['category_active'] ?? true) === true && ($product['subcategory_active'] ?? true) === true),
            ));
        } catch (PromotionContractException) {
            return $this->catalogUnavailable();
        }

        usort($products, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return response()->json([
            'products' => $products,
        ]);
    }

    /** @param array<string,mixed> $product @return array<string,mixed> */
    private function publicProduct(array $product, ProductPromotionPriceResolver $prices): array
    {
        $price = $prices->resolve($product['price_cop'], $product['promotion'] ?? null, now());
        $public = [
            'id' => $product['product_id'], 'img' => $product['legacy_img'], 'cat' => $product['category'],
            'sub' => $product['subcategory'], 'name' => $product['name'], 'pres' => $product['presentation'],
            'price' => $price['effective_price_cop'], 'image' => $product['image_path'],
            'inventory' => $product['inventory'], 'active' => $product['active'],
        ];
        if ($price['has_active_promotion']) {
            $public['promotion'] = [
                'active' => true, 'type' => $price['discount_type'], 'value' => $price['discount_value'],
                'originalPrice' => $price['base_price_cop'], 'effectivePrice' => $price['effective_price_cop'],
                'discountCop' => $price['discount_cop'],
            ];
        }

        return $public;
    }

    private function catalogUnavailable(): JsonResponse
    {
        return response()->json([
            'message' => 'El catálogo no está disponible en este momento.',
        ], 503, ['Retry-After' => '60']);
    }
}
