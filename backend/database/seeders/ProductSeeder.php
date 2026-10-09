<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Intentionally contains no product literals. Use products:import-legacy only
 * after migrations have been explicitly approved and run.
 */
class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->warn('ProductSeeder is intentionally empty. Use products:import-legacy with an approved legacy source.');
    }
}
