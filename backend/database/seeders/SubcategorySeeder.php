<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Subcategory;
use Illuminate\Database\Seeder;
use RuntimeException;

class SubcategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['category_slug' => 'alimentos', 'name' => 'Adobos', 'slug' => 'adobos', 'active' => true, 'sort_order' => 0],
            ['category_slug' => 'alimentos', 'name' => 'Alimento en Polvo', 'slug' => 'alimento-en-polvo', 'active' => true, 'sort_order' => 1],
            ['category_slug' => 'alimentos', 'name' => 'Café', 'slug' => 'cafe', 'active' => true, 'sort_order' => 2],
            ['category_slug' => 'alimentos', 'name' => 'Embutidos', 'slug' => 'embutidos', 'active' => true, 'sort_order' => 3],
            ['category_slug' => 'alimentos', 'name' => 'Enlatados', 'slug' => 'enlatados', 'active' => true, 'sort_order' => 4],
            ['category_slug' => 'alimentos', 'name' => 'Harinas', 'slug' => 'harinas', 'active' => true, 'sort_order' => 5],
            ['category_slug' => 'alimentos', 'name' => 'Mantequillas', 'slug' => 'mantequillas', 'active' => true, 'sort_order' => 6],
            ['category_slug' => 'alimentos', 'name' => 'Panadería', 'slug' => 'panaderia', 'active' => true, 'sort_order' => 7],
            ['category_slug' => 'alimentos', 'name' => 'Pastas', 'slug' => 'pastas', 'active' => true, 'sort_order' => 8],
            ['category_slug' => 'alimentos', 'name' => 'Proteínas', 'slug' => 'proteinas', 'active' => true, 'sort_order' => 9],
            ['category_slug' => 'alimentos', 'name' => 'Quesos', 'slug' => 'quesos', 'active' => true, 'sort_order' => 10],
            ['category_slug' => 'bebidas', 'name' => 'Gaseosa', 'slug' => 'gaseosa', 'active' => true, 'sort_order' => 0],
            ['category_slug' => 'bebidas', 'name' => 'Malta', 'slug' => 'malta', 'active' => true, 'sort_order' => 1],
            ['category_slug' => 'cuidado-del-bebe', 'name' => 'Alimento Infantil', 'slug' => 'alimento-infantil', 'active' => true, 'sort_order' => 0],
            ['category_slug' => 'cuidado-del-bebe', 'name' => 'Colonias', 'slug' => 'colonias', 'active' => true, 'sort_order' => 1],
            ['category_slug' => 'cuidado-del-bebe', 'name' => 'Jabones', 'slug' => 'jabones', 'active' => true, 'sort_order' => 2],
            ['category_slug' => 'cuidado-del-bebe', 'name' => 'Jarabes', 'slug' => 'jarabes', 'active' => true, 'sort_order' => 3],
            ['category_slug' => 'cuidado-personal', 'name' => 'Cremas', 'slug' => 'cremas', 'active' => true, 'sort_order' => 0],
            ['category_slug' => 'cuidado-personal', 'name' => 'Gel para Cabello', 'slug' => 'gel-para-cabello', 'active' => true, 'sort_order' => 1],
            ['category_slug' => 'cuidado-personal', 'name' => 'Jabones', 'slug' => 'jabones', 'active' => true, 'sort_order' => 2],
            ['category_slug' => 'cuidado-personal', 'name' => 'Jarabes', 'slug' => 'jarabes', 'active' => true, 'sort_order' => 3],
            ['category_slug' => 'cuidado-personal', 'name' => 'Talcos', 'slug' => 'talcos', 'active' => true, 'sort_order' => 4],
            ['category_slug' => 'cuidado-personal', 'name' => 'Vitaminas', 'slug' => 'vitaminas', 'active' => true, 'sort_order' => 5],
            ['category_slug' => 'dulces-y-snacks', 'name' => 'Bocadillos', 'slug' => 'bocadillos', 'active' => true, 'sort_order' => 0],
            ['category_slug' => 'dulces-y-snacks', 'name' => 'Chocolates', 'slug' => 'chocolates', 'active' => true, 'sort_order' => 1],
            ['category_slug' => 'dulces-y-snacks', 'name' => 'Galletas', 'slug' => 'galletas', 'active' => true, 'sort_order' => 2],
            ['category_slug' => 'dulces-y-snacks', 'name' => 'Snacks', 'slug' => 'snacks', 'active' => true, 'sort_order' => 3],
            ['category_slug' => 'dulces-y-snacks', 'name' => 'Turrón', 'slug' => 'turron', 'active' => true, 'sort_order' => 4],
            ['category_slug' => 'lacteos', 'name' => 'Bebida Láctea', 'slug' => 'bebida-lactea', 'active' => true, 'sort_order' => 0],
            ['category_slug' => 'lacteos', 'name' => 'Bebida Láctea en Polvo', 'slug' => 'bebida-lactea-en-polvo', 'active' => true, 'sort_order' => 1],
            ['category_slug' => 'lacteos', 'name' => 'Leche Condensada', 'slug' => 'leche-condensada', 'active' => true, 'sort_order' => 2],
            ['category_slug' => 'lacteos', 'name' => 'Leche en Polvo', 'slug' => 'leche-en-polvo', 'active' => true, 'sort_order' => 3],
            ['category_slug' => 'lacteos', 'name' => 'Leche Evaporada', 'slug' => 'leche-evaporada', 'active' => true, 'sort_order' => 4],
            ['category_slug' => 'licor', 'name' => 'Aguardiente', 'slug' => 'aguardiente', 'active' => true, 'sort_order' => 0],
            ['category_slug' => 'licor', 'name' => 'Anís', 'slug' => 'anis', 'active' => true, 'sort_order' => 1],
            ['category_slug' => 'licor', 'name' => 'Brandy', 'slug' => 'brandy', 'active' => true, 'sort_order' => 2],
            ['category_slug' => 'licor', 'name' => 'Cerveza', 'slug' => 'cerveza', 'active' => true, 'sort_order' => 3],
            ['category_slug' => 'licor', 'name' => 'Cremas', 'slug' => 'cremas', 'active' => true, 'sort_order' => 4],
            ['category_slug' => 'licor', 'name' => 'Ron', 'slug' => 'ron', 'active' => true, 'sort_order' => 5],
            ['category_slug' => 'licor', 'name' => 'Sangría', 'slug' => 'sangria', 'active' => true, 'sort_order' => 6],
            ['category_slug' => 'licor', 'name' => 'Vodka', 'slug' => 'vodka', 'active' => true, 'sort_order' => 7],
            ['category_slug' => 'otros', 'name' => 'Chimó', 'slug' => 'chimo', 'active' => true, 'sort_order' => 0],
            ['category_slug' => 'salsas-y-aderezos', 'name' => 'Salsas', 'slug' => 'salsas', 'active' => true, 'sort_order' => 0],
            ['category_slug' => 'salsas-y-aderezos', 'name' => 'Salsas en Polvo', 'slug' => 'salsas-en-polvo', 'active' => true, 'sort_order' => 1],
        ] as $data) {
            $categoryId = Category::query()->where('slug', $data['category_slug'])->value('id');

            if ($categoryId === null) {
                throw new RuntimeException("Missing category [{$data['category_slug']}] while seeding subcategories.");
            }

            Subcategory::query()->updateOrCreate(
                ['category_id' => $categoryId, 'slug' => $data['slug']],
                [
                    'name' => $data['name'],
                    'active' => $data['active'],
                    'sort_order' => $data['sort_order'],
                ],
            );
        }
    }
}
