<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'Alimentos', 'slug' => 'alimentos', 'active' => true, 'sort_order' => 0],
            ['name' => 'Bebidas', 'slug' => 'bebidas', 'active' => true, 'sort_order' => 1],
            ['name' => 'Cuidado del Bebé', 'slug' => 'cuidado-del-bebe', 'active' => true, 'sort_order' => 2],
            ['name' => 'Cuidado Personal', 'slug' => 'cuidado-personal', 'active' => true, 'sort_order' => 3],
            ['name' => 'Dulces y Snacks', 'slug' => 'dulces-y-snacks', 'active' => true, 'sort_order' => 4],
            ['name' => 'Lácteos', 'slug' => 'lacteos', 'active' => true, 'sort_order' => 5],
            ['name' => 'Licor', 'slug' => 'licor', 'active' => true, 'sort_order' => 6],
            ['name' => 'Otros', 'slug' => 'otros', 'active' => true, 'sort_order' => 7],
            ['name' => 'Salsas y Aderezos', 'slug' => 'salsas-y-aderezos', 'active' => true, 'sort_order' => 8],
        ] as $category) {
            Category::query()->updateOrCreate(['slug' => $category['slug']], $category);
        }
    }
}
