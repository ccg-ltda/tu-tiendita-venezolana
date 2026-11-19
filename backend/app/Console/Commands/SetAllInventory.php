<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

/** Pone la misma existencia a todos los productos (p. ej. 1000 al arrancar). Pide confirmación y no toca precios ni estado. */
final class SetAllInventory extends Command
{
    protected $signature = 'inventory:set-all {amount=1000 : Existencias que tendrá cada producto} {--only-empty : Solo a los que están en 0} {--force : No pedir confirmación}';

    protected $description = 'Pone la misma existencia a todos los productos (por defecto 1000).';

    public function handle(): int
    {
        $amount = (int) $this->argument('amount');
        if ($amount < 0 || $amount > 1000000) {
            $this->error('La cantidad debe estar entre 0 y 1.000.000.');

            return self::FAILURE;
        }
        $query = Product::query();
        if ($this->option('only-empty')) $query->where('inventory', 0);
        $count = (clone $query)->count();
        if ($count === 0) {
            $this->info('No hay productos para actualizar.');

            return self::SUCCESS;
        }
        if (! $this->option('force') && ! $this->confirm("Se pondrán {$amount} unidades a {$count} productos en la base «".config('database.connections.mysql.database')."». ¿Continuar?")) {
            $this->warn('Cancelado. No se cambió nada.');

            return self::FAILURE;
        }
        $query->update(['inventory' => $amount, 'revision' => \Illuminate\Support\Facades\DB::raw('revision + 1')]);
        $this->info("Listo: {$count} productos con {$amount} unidades.");

        return self::SUCCESS;
    }
}
