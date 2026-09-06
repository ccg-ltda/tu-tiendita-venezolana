<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    /**
     * Return the public catalog using the legacy frontend contract.
     */
    public function index(): JsonResponse
    {
        $products = Product::query()
            ->where('active', true)
            ->orderBy('id')
            ->get()
            ->map(static fn (Product $product): array => [
                'id' => $product->id,
                'img' => $product->img,
                'cat' => $product->category,
                'sub' => $product->subcategory,
                'name' => $product->name,
                'pres' => $product->presentation,
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
