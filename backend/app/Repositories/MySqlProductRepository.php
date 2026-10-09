<?php

namespace App\Repositories;

use App\Models\Product;
use App\Products\ProductNormalizer;
use App\Services\PersistenceException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Authoritative product persistence for the SQL cutover.
 *
 * Return values intentionally retain the established product API shape so existing
 * controllers and frontend payloads do not need a contract change.
 */
class MySqlProductRepository
{
    public function __construct(private readonly MySqlCategoryRepository $categories) {}
    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return Product::query()->with(['categoryRelation', 'subcategoryRelation'])->orderBy('id')->get()->map(fn (Product $product): array => $this->contract($product))->all();
    }

    /** @return array{product:array<string,mixed>}|null */
    public function findById(int $id): ?array
    {
        $product = Product::query()->with(['categoryRelation', 'subcategoryRelation'])->find($id);

        return $product === null ? null : ['product' => $this->contract($product)];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function create(array $input): array
    {
        return DB::connection('mysql')->transaction(function () use ($input): array {
            $now = now('UTC');
            $classification = $this->categories->classification((int) $input['category_id'], (int) $input['subcategory_id']);
            $candidate = ProductNormalizer::normalize(array_merge($input, [
                'category' => $classification['category']->name,
                'subcategory' => $classification['subcategory']->name,
                'product_id' => 1,
                'created_at' => $now->format('Y-m-d\\TH:i:s.v\\Z'),
                'updated_at' => $now->format('Y-m-d\\TH:i:s.v\\Z'),
                'revision' => 1,
            ]));
            if ($candidate === null) throw new PersistenceException(422, 'INVALID_REQUEST');

            $product = Product::on('mysql')->create([
                'category_id' => $classification['category']->id, 'subcategory_id' => $classification['subcategory']->id,
                'category' => $candidate['category'], 'subcategory' => $candidate['subcategory'],
                'name' => $candidate['name'], 'presentation' => $candidate['presentation'],
                'price_cop' => $candidate['price_cop'], 'inventory' => $candidate['inventory'],
                'active' => $candidate['active'], 'image_path' => $candidate['image_path'],
                'legacy_img' => $candidate['legacy_img'], 'revision' => 1,
            ]);

            return $this->contract($product->fresh(['categoryRelation', 'subcategoryRelation']));
        });
    }

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    public function update(int $id, int $expectedRevision, array $changes): array
    {
        return $this->write($id, $expectedRevision, $changes);
    }

    /** @return array<string,mixed> */
    public function setActive(int $id, int $expectedRevision, bool $active): array
    {
        return $this->write($id, $expectedRevision, ['active' => $active]);
    }

    public function syncStatus(): string
    {
        return 'synced';
    }

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    private function write(int $id, int $expectedRevision, array $changes): array
    {
        return DB::connection('mysql')->transaction(function () use ($id, $expectedRevision, $changes): array {
            $current = Product::on('mysql')->with(['categoryRelation', 'subcategoryRelation'])->find($id);
            if ($current === null) throw new PersistenceException(404, 'PRODUCT_NOT_FOUND');
            if ($current->revision !== $expectedRevision) throw new PersistenceException(409, 'REVISION_CONFLICT');

            $classification = array_key_exists('category_id', $changes) || array_key_exists('subcategory_id', $changes)
                ? $this->categories->classification((int) ($changes['category_id'] ?? $current->category_id), (int) ($changes['subcategory_id'] ?? $current->subcategory_id))
                : null;
            $next = ProductNormalizer::normalize(array_merge($this->contract($current), $changes, $classification ? [
                'category' => $classification['category']->name, 'subcategory' => $classification['subcategory']->name,
            ] : [], [
                'updated_at' => now('UTC')->format('Y-m-d\\TH:i:s.v\\Z'),
                'revision' => $expectedRevision + 1,
            ]));
            if ($next === null) throw new PersistenceException(422, 'INVALID_REQUEST');

            $updated = Product::on('mysql')->whereKey($id)->where('revision', $expectedRevision)->update([
                'category_id' => $classification['category']->id ?? $current->category_id, 'subcategory_id' => $classification['subcategory']->id ?? $current->subcategory_id,
                'category' => $next['category'], 'subcategory' => $next['subcategory'],
                'name' => $next['name'], 'presentation' => $next['presentation'],
                'price_cop' => $next['price_cop'], 'inventory' => $next['inventory'],
                'active' => $next['active'], 'image_path' => $next['image_path'],
                'legacy_img' => $next['legacy_img'], 'revision' => $next['revision'],
                'updated_at' => now('UTC'),
            ]);
            if ($updated !== 1) throw new PersistenceException(409, 'REVISION_CONFLICT');

            return $this->contract(Product::on('mysql')->with(['categoryRelation', 'subcategoryRelation'])->findOrFail($id));
        });
    }

    /** @return array<string,mixed> */
    private function contract(Product $product): array
    {
        return [
            'product_id' => (int) $product->id, 'category_id' => $product->category_id === null ? null : (int) $product->category_id,
            'subcategory_id' => $product->subcategory_id === null ? null : (int) $product->subcategory_id, 'category' => $product->category,
            'subcategory' => $product->subcategory, 'name' => $product->name,
            'presentation' => $product->presentation, 'price_cop' => (int) $product->price_cop,
            'inventory' => (int) $product->inventory, 'active' => (bool) $product->active,
            'category_active' => $product->category_id === null || ($product->relationLoaded('categoryRelation') && $product->categoryRelation !== null && (bool) $product->categoryRelation->active),
            'subcategory_active' => $product->subcategory_id === null || ($product->relationLoaded('subcategoryRelation') && $product->subcategoryRelation !== null && (bool) $product->subcategoryRelation->active),
            'image_path' => $product->image_path, 'legacy_img' => $product->legacy_img,
            'created_at' => $this->timestamp($product->created_at),
            'updated_at' => $this->timestamp($product->updated_at), 'revision' => (int) $product->revision,
        ];
    }

    private function timestamp(mixed $value): string
    {
        return Carbon::parse($value)->utc()->format('Y-m-d\\TH:i:s.v\\Z');
    }
}
