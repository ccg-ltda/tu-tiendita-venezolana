<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        $connection = DB::connection('mysql');
        $rows = $connection->table('products')->select('id', 'category', 'subcategory')->orderBy('category')->orderBy('subcategory')->orderBy('id')->get();
        $categories = [];
        $subcategories = [];
        $usedCategorySlugs = [];
        $usedSubcategorySlugs = [];
        $now = now('UTC');

        foreach ($rows as $row) {
            $categoryName = trim((string) $row->category);
            $subcategoryName = trim((string) $row->subcategory);
            if ($categoryName === '' || $subcategoryName === '') continue;
            $categoryKey = mb_strtolower($categoryName);
            if (! isset($categories[$categoryKey])) {
                $slug = $this->uniqueSlug($categoryName, $usedCategorySlugs, 'categoria');
                $id = $connection->table('categories')->insertGetId(['name' => $categoryName, 'slug' => $slug, 'active' => true, 'sort_order' => count($categories), 'created_at' => $now, 'updated_at' => $now]);
                $categories[$categoryKey] = $id;
            }
            $subcategoryKey = $categories[$categoryKey].'|'.mb_strtolower($subcategoryName);
            if (! isset($subcategories[$subcategoryKey])) {
                $categoryId = $categories[$categoryKey];
                if (! isset($usedSubcategorySlugs[$categoryId])) $usedSubcategorySlugs[$categoryId] = [];
                $slug = $this->uniqueSlug($subcategoryName, $usedSubcategorySlugs[$categoryId], 'subcategoria');
                $id = $connection->table('subcategories')->insertGetId(['category_id' => $categories[$categoryKey], 'name' => $subcategoryName, 'slug' => $slug, 'active' => true, 'sort_order' => count(array_filter(array_keys($subcategories), fn ($key) => str_starts_with($key, $categories[$categoryKey].'|'))), 'created_at' => $now, 'updated_at' => $now]);
                $subcategories[$subcategoryKey] = $id;
            }
            $connection->table('products')->where('id', $row->id)->update(['category_id' => $categories[$categoryKey], 'subcategory_id' => $subcategories[$subcategoryKey]]);
        }
    }
    public function down(): void { DB::connection('mysql')->table('products')->update(['category_id' => null, 'subcategory_id' => null]); }
    private function uniqueSlug(string $name, array &$used, string $fallback): string
    {
        $base = Str::slug($name) ?: $fallback;
        $slug = $base; $suffix = 2;
        while (isset($used[$slug])) $slug = $base.'-'.($suffix++);
        $used[$slug] = true;
        return $slug;
    }
};
