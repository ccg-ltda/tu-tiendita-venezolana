<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use JsonException;
use LogicException;
use RuntimeException;

class ProductSeeder extends Seeder
{
    /**
     * Import the initial catalog only into an empty products table.
     */
    public function run(): void
    {
        $path = database_path('data/products.json');

        if (!is_file($path)) {
            throw new RuntimeException("No se encontró el snapshot del catálogo: {$path}");
        }

        try {
            $catalog = json_decode(
                file_get_contents($path) ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('El snapshot del catálogo no contiene JSON válido.', previous: $exception);
        }

        if (!is_array($catalog) || !array_is_list($catalog)) {
            throw new RuntimeException('El snapshot del catálogo debe contener un array de productos.');
        }

        $products = $this->validatedProducts($catalog);
        $timestamp = now();

        DB::transaction(function () use ($products, $timestamp): void {
            if (Product::query()->exists()) {
                throw new LogicException('La tabla products ya contiene datos; el seed inicial fue cancelado para evitar sobrescrituras.');
            }

            Product::query()->insert(array_map(
                static fn (array $product): array => [
                    'id' => $product['id'],
                    'img' => $product['img'],
                    'category' => $product['cat'],
                    'subcategory' => $product['sub'],
                    'name' => $product['name'],
                    'presentation' => $product['pres'],
                    'price' => $product['price'],
                    'image' => $product['image'],
                    'inventory' => 20,
                    'active' => true,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
                $products,
            ));
        });
    }

    /**
     * @param array<mixed> $catalog
     * @return list<array{id: int, img: string|null, cat: string, sub: string, name: string, pres: string, price: int, image: string|null}>
     */
    private function validatedProducts(array $catalog): array
    {
        $products = [];
        $ids = [];

        foreach ($catalog as $product) {
            if (!is_array($product)
                || !isset($product['id'], $product['cat'], $product['sub'], $product['name'], $product['pres'], $product['price'])
                || !is_int($product['id'])
                || !is_int($product['price'])
                || $product['id'] < 1
                || $product['price'] < 0
                || !$this->isRequiredString($product['cat'])
                || !$this->isRequiredString($product['sub'])
                || !$this->isRequiredString($product['name'])
                || !$this->isRequiredString($product['pres'])
                || !$this->isNullableString($product['img'] ?? null)
                || !$this->isNullableString($product['image'] ?? null)
                || isset($ids[$product['id']])) {
                throw new RuntimeException('El snapshot del catálogo contiene un producto inválido o un ID duplicado.');
            }

            $ids[$product['id']] = true;
            $products[] = [
                'id' => $product['id'],
                'img' => $product['img'] ?? null,
                'cat' => trim($product['cat']),
                'sub' => trim($product['sub']),
                'name' => trim($product['name']),
                'pres' => trim($product['pres']),
                'price' => $product['price'],
                'image' => $product['image'] ?? null,
            ];
        }

        if (count($products) !== 198 || array_keys($ids) !== range(1, 198)) {
            throw new RuntimeException('El snapshot debe contener exactamente los IDs históricos del 1 al 198.');
        }

        return $products;
    }

    private function isRequiredString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function isNullableString(mixed $value): bool
    {
        return $value === null || is_string($value);
    }
}
