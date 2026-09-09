<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Product\StoreProductRequest;
use App\Http\Requests\Admin\Product\UpdateProductRequest;
use App\Http\Requests\Admin\Product\UpdateProductStatusRequest;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

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
            ->map(fn (Product $product): array => $this->productPayload($product))
            ->values();

        return response()->json([
            'products' => $products,
        ]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $storedImage = null;

        try {
            $storedImage = $request->hasFile('image')
                ? $this->storeProductImage($request->file('image'))
                : null;

            $product = Product::query()->create([
                'img' => null,
                'category' => $validated['category'],
                'subcategory' => $validated['subcategory'],
                'name' => $validated['name'],
                'presentation' => $validated['presentation'],
                'price' => $validated['price'],
                'image' => $storedImage,
                'inventory' => 0,
                'active' => true,
            ]);
        } catch (Throwable $exception) {
            $this->deleteManagedImage($storedImage);

            throw $exception;
        }

        return response()->json(['product' => $this->productPayload($product)], 201);
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $validated = $request->validated();
        unset($validated['image']);
        $storedImage = null;
        $previousImage = $product->image;

        try {
            $storedImage = $request->hasFile('image')
                ? $this->storeProductImage($request->file('image'))
                : null;

            $product->fill($validated);
            if ($storedImage !== null) {
                $product->image = $storedImage;
            }
            $product->save();
        } catch (Throwable $exception) {
            $this->deleteManagedImage($storedImage);

            throw $exception;
        }

        if ($storedImage !== null) {
            $this->deleteManagedImage($previousImage);
        }

        return response()->json(['product' => $this->productPayload($product)]);
    }

    public function updateStatus(UpdateProductStatusRequest $request, Product $product): JsonResponse
    {
        $product->active = $request->validated('active');
        $product->save();

        return response()->json(['product' => $this->productPayload($product)]);
    }

    private function productPayload(Product $product): array
    {
        return [
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
        ];
    }

    private function storeProductImage(UploadedFile $image): string
    {
        return '/storage/'.$image->store('products', 'public');
    }

    private function deleteManagedImage(?string $image): void
    {
        if (! is_string($image) || ! str_starts_with($image, '/storage/products/')) {
            return;
        }

        $path = substr($image, strlen('/storage/'));
        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
