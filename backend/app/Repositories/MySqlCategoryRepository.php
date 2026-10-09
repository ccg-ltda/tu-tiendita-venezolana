<?php

namespace App\Repositories;

use App\Models\Category;
use App\Models\Subcategory;
use App\Services\PersistenceException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MySqlCategoryRepository
{
    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return Category::query()->with(['subcategories' => fn ($query) => $query->withCount('products')->orderBy('sort_order')->orderBy('name')])
            ->withCount(['products', 'subcategories'])->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (Category $category) => $this->contract($category))->all();
    }

    /** @return array<string,mixed> */
    public function create(string $name): array
    {
        try {
            return DB::connection('mysql')->transaction(function () use ($name): array {
                $name = $this->name($name);
                if (Category::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) throw new PersistenceException(422, 'CATEGORY_DUPLICATE');
                $category = Category::query()->create(['name' => $name, 'slug' => $this->slug($name), 'active' => true, 'sort_order' => ((int) Category::query()->max('sort_order')) + 1]);
                return $this->contract($category->loadCount(['products', 'subcategories'])->load('subcategories'));
            });
        } catch (QueryException $exception) { throw new PersistenceException(422, 'CATEGORY_DUPLICATE', $exception); }
    }

    /** @return array<string,mixed> */
    public function update(int $id, string $name): array
    {
        try {
            return DB::connection('mysql')->transaction(function () use ($id, $name): array {
                $category = Category::query()->find($id);
                if ($category === null) throw new PersistenceException(404, 'CATEGORY_NOT_FOUND');
                $name = $this->name($name);
                if (Category::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->where('id', '!=', $id)->exists()) throw new PersistenceException(422, 'CATEGORY_DUPLICATE');
                $category->fill(['name' => $name, 'slug' => $this->slug($name, $id)])->save();
                // Keep the legacy snapshot fields in products coherent during the FK transition.
                DB::connection('mysql')->table('products')->where('category_id', $id)->update(['category' => $category->name, 'updated_at' => now('UTC')]);
                return $this->contract($category->fresh()->loadCount(['products', 'subcategories'])->load('subcategories'));
            });
        } catch (QueryException $exception) { throw new PersistenceException(422, 'CATEGORY_DUPLICATE', $exception); }
    }

    /** @return array<string,mixed> */
    public function setActive(int $id, bool $active): array
    {
        $category = Category::query()->find($id);
        if ($category === null) throw new PersistenceException(404, 'CATEGORY_NOT_FOUND');
        $category->update(['active' => $active]);
        return $this->contract($category->fresh()->loadCount(['products', 'subcategories'])->load('subcategories'));
    }

    /** @return array{category:Category,subcategory:Subcategory} */
    public function classification(int $categoryId, int $subcategoryId): array
    {
        $category = Category::query()->whereKey($categoryId)->where('active', true)->first();
        $subcategory = Subcategory::query()->whereKey($subcategoryId)->where('category_id', $categoryId)->where('active', true)->first();
        if ($category === null || $subcategory === null) throw new PersistenceException(422, 'INVALID_CLASSIFICATION');
        return compact('category', 'subcategory');
    }

    /** @return array<string,mixed> */
    public function contract(Category $category): array
    {
        return ['id' => (int) $category->id, 'name' => $category->name, 'slug' => $category->slug, 'active' => (bool) $category->active, 'sort_order' => (int) $category->sort_order, 'products_count' => (int) ($category->products_count ?? 0), 'subcategories_count' => (int) ($category->subcategories_count ?? $category->subcategories->count()), 'subcategories' => $category->relationLoaded('subcategories') ? $category->subcategories->map(fn (Subcategory $subcategory) => ['id' => (int) $subcategory->id, 'category_id' => (int) $subcategory->category_id, 'name' => $subcategory->name, 'slug' => $subcategory->slug, 'active' => (bool) $subcategory->active, 'sort_order' => (int) $subcategory->sort_order, 'products_count' => (int) ($subcategory->products_count ?? 0)])->values()->all() : []];
    }

    private function name(string $name): string
    {
        $name = trim($name);
        if ($name === '') throw new PersistenceException(422, 'INVALID_CATEGORY_NAME');
        if (mb_strtolower($name) === 'promociones') throw new PersistenceException(422, 'VIRTUAL_CATEGORY_RESERVED');

        return $name;
    }
    private function slug(string $name, ?int $exceptId = null): string
    {
        $base = Str::slug(trim($name)) ?: 'categoria'; $slug = $base; $suffix = 2;
        $query = fn (string $value) => Category::query()->where('slug', $value)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists();
        while ($query($slug)) $slug = $base.'-'.($suffix++);
        return $slug;
    }
}
