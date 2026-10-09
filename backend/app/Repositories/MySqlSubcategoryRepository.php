<?php

namespace App\Repositories;

use App\Models\Category;
use App\Models\Subcategory;
use App\Services\PersistenceException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MySqlSubcategoryRepository
{
    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $subcategory = Subcategory::query()->find($id);

        return $subcategory === null ? null : $this->contract($subcategory);
    }

    /** @return array<string,mixed> */
    public function create(int $categoryId, string $name): array
    {
        try { return DB::connection('mysql')->transaction(function () use ($categoryId, $name): array {
            if (! Category::query()->whereKey($categoryId)->exists()) throw new PersistenceException(422, 'CATEGORY_NOT_FOUND');
            $name = $this->name($name);
            if (Subcategory::query()->where('category_id', $categoryId)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) throw new PersistenceException(422, 'SUBCATEGORY_DUPLICATE');
            $subcategory = Subcategory::query()->create(['category_id' => $categoryId, 'name' => $name, 'slug' => $this->slug($categoryId, $name), 'active' => true, 'sort_order' => ((int) Subcategory::query()->where('category_id', $categoryId)->max('sort_order')) + 1]);
            return $this->contract($subcategory);
        }); } catch (QueryException $exception) { throw new PersistenceException(422, 'SUBCATEGORY_DUPLICATE', $exception); }
    }
    /** @return array<string,mixed> */
    public function update(int $id, string $name): array
    {
        try { return DB::connection('mysql')->transaction(function () use ($id, $name): array {
            $subcategory = Subcategory::query()->find($id); if ($subcategory === null) throw new PersistenceException(404, 'SUBCATEGORY_NOT_FOUND');
            $name = $this->name($name);
            if (Subcategory::query()->where('category_id', $subcategory->category_id)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->where('id', '!=', $id)->exists()) throw new PersistenceException(422, 'SUBCATEGORY_DUPLICATE');
            $subcategory->fill(['name' => $name, 'slug' => $this->slug($subcategory->category_id, $name, $id)])->save();
            DB::connection('mysql')->table('products')->where('subcategory_id', $id)->update(['subcategory' => $subcategory->name, 'updated_at' => now('UTC')]);
            return $this->contract($subcategory->fresh());
        }); } catch (QueryException $exception) { throw new PersistenceException(422, 'SUBCATEGORY_DUPLICATE', $exception); }
    }
    /** @return array<string,mixed> */
    public function setActive(int $id, bool $active): array
    { $subcategory = Subcategory::query()->find($id); if ($subcategory === null) throw new PersistenceException(404, 'SUBCATEGORY_NOT_FOUND'); $subcategory->update(['active' => $active]); return $this->contract($subcategory->fresh()); }
    /** @return array<string,mixed> */
    private function contract(Subcategory $subcategory): array { return ['id'=>(int)$subcategory->id,'category_id'=>(int)$subcategory->category_id,'name'=>$subcategory->name,'slug'=>$subcategory->slug,'active'=>(bool)$subcategory->active,'sort_order'=>(int)$subcategory->sort_order,'products_count'=>(int)($subcategory->products_count ?? 0)]; }
    private function name(string $name): string { $name=trim($name);if($name==='')throw new PersistenceException(422,'INVALID_SUBCATEGORY_NAME');return $name; }
    private function slug(int $categoryId,string $name,?int $exceptId=null):string { $base=Str::slug(trim($name))?:'subcategoria';$slug=$base;$suffix=2;$exists=fn($value)=>Subcategory::query()->where('category_id',$categoryId)->where('slug',$value)->when($exceptId,fn($q)=>$q->where('id','!=',$exceptId))->exists();while($exists($slug))$slug=$base.'-'.($suffix++);return $slug; }
}
