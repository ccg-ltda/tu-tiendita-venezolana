<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    /**
     * Return the full catalog for authenticated administrators.
     */
    public function index(): JsonResponse
    {
        $products = Product::query()
            ->orderBy('id')
            ->get()
            ->map(static fn (Product $product): array => [
                'id' => $product->id,
                'img' => $product->img,
                'category' => $product->category,
                'subcategory' => $product->subcategory,
                'name' => $product->name,
                'presentation' => $product->presentation,
                'price' => $product->price,
                'image' => $product->image,
                'inventory' => $product->inventory,
                'active' => $product->active,
            ])
            ->values();

        return response()->json([
            'products' => $products,
        ]);
    }
}
