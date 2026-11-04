<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ProductImageStore;
use Illuminate\Console\Command;

/**
 * One-off: las fotos "legacy" de producto (public/assets/products/*.jpg|png,
 * empaquetadas con el frontend) ya se convirtieron a .webp con
 * scripts/convert-legacy-images.mjs (mismo basename, solo cambia la
 * extensión). Este comando actualiza products.image_path para que apunte
 * al .webp nuevo. No toca fotos subidas vía admin (esas ya viven en
 * storage/ como .webp desde que se suben).
 */
class ConvertLegacyProductImageExtensions extends Command
{
    protected $signature = 'products:point-legacy-images-to-webp {--dry-run : Solo muestra qué cambiaria, no guarda nada}';

    protected $description = 'Actualiza image_path de productos con fotos legacy (jpg/png) para que apunten al .webp ya convertido';

    public function handle(ProductImageStore $images): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $candidates = Product::query()->whereNotNull('image_path')->get();

        $updated = 0;
        foreach ($candidates as $product) {
            $path = $product->image_path;
            // Las rutas gestionadas (/storage/products/...) ya estan en webp
            // desde que se suben; solo tocamos las legacy sin ese prefijo.
            if ($images->isManagedPath($path)) continue;
            if (! preg_match('/\.(jpe?g|png)$/i', $path)) continue;

            $newPath = preg_replace('/\.(jpe?g|png)$/i', '.webp', $path);
            $this->line("{$product->id}: {$path} -> {$newPath}");

            if (! $dryRun) {
                $product->image_path = $newPath;
                $product->save();
            }
            $updated++;
        }

        $this->info($dryRun ? "{$updated} productos cambiarian (dry-run, nada guardado)." : "{$updated} productos actualizados.");

        return self::SUCCESS;
    }
}
