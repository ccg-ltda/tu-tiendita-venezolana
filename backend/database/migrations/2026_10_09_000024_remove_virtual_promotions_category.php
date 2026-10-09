<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Promociones is a storefront grouping, never a persisted classification.
     * Existing products cannot be reassigned safely because their real category
     * is unknown, so they are preserved but hidden until an administrator
     * assigns a valid SQL category and subcategory.
     */
    public function up(): void
    {
        DB::connection('mysql')->transaction(function (): void {
            $connection = DB::connection('mysql');
            $categoryIds = $connection->table('categories')
                ->whereRaw('LOWER(name) = ?', ['promociones'])
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
            $subcategoryIds = $categoryIds === []
                ? []
                : $connection->table('subcategories')->whereIn('category_id', $categoryIds)->pluck('id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();

            $products = $connection->table('products')->where(function ($query) use ($categoryIds, $subcategoryIds): void {
                $query->whereRaw('LOWER(category) = ?', ['promociones']);
                if ($categoryIds !== []) {
                    $query->orWhereIn('category_id', $categoryIds);
                }
                if ($subcategoryIds !== []) {
                    $query->orWhereIn('subcategory_id', $subcategoryIds);
                }
            });

            $products->update([
                'category_id' => null,
                'subcategory_id' => null,
                'active' => false,
                'updated_at' => now('UTC'),
            ]);

            if ($categoryIds !== []) {
                $connection->table('subcategories')->whereIn('category_id', $categoryIds)->delete();
                $connection->table('categories')->whereIn('id', $categoryIds)->delete();
            }
        });
    }

    /** The original business category is unknowable, so this cleanup is irreversible. */
    public function down(): void
    {
    }
};
