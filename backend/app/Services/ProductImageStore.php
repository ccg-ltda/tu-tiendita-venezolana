<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;

class ProductImageStore
{
    private const DIRECTORY = 'products';

    // Tope de peso por imagen de producto (ver brief SEO, Problema 5).
    private const MAX_BYTES = 150 * 1024;
    private const STARTING_QUALITY = 80;
    private const MIN_QUALITY = 40;

    public function store(UploadedFile $file): string
    {
        $mime = $file->getMimeType();
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

        if (! isset($extensions[$mime]) || @getimagesize($file->getRealPath()) === false) {
            throw new \InvalidArgumentException('Invalid product image.');
        }

        $webp = $this->toWebp($file);
        $filename = bin2hex(random_bytes(16)).'.webp';
        $storedPath = Storage::disk('public')->put(self::DIRECTORY.'/'.$filename, $webp, 'public');

        if ($storedPath === false) {
            throw new \RuntimeException('Product image could not be stored.');
        }

        return '/storage/'.self::DIRECTORY.'/'.$filename;
    }

    /**
     * Convierte a WebP y baja la calidad en pasos hasta caber en MAX_BYTES,
     * sin bajar de MIN_QUALITY (evita destruir la imagen por quedar liviana).
     */
    private function toWebp(UploadedFile $file): string
    {
        $manager = new ImageManager(match (true) {
            extension_loaded('gd') => new GdDriver(),
            extension_loaded('imagick') => new ImagickDriver(),
            default => throw new \RuntimeException('No hay driver de imagenes (gd/imagick) disponible en este servidor.'),
        });

        $image = $manager->read($file->getRealPath());

        for ($quality = self::STARTING_QUALITY; $quality >= self::MIN_QUALITY; $quality -= 10) {
            $encoded = (string) $image->toWebp($quality);
            if (strlen($encoded) <= self::MAX_BYTES) {
                return $encoded;
            }
        }

        // Ni en la calidad minima entro en el limite: se guarda igual (la
        // validacion de dimensiones/tamano de archivo ya limita el excedente),
        // mejor una imagen pesada que una venta rota por un producto sin foto.
        return $encoded;
    }

    public function forget(string $path): void
    {
        $storedPath = $this->storedPath($path);

        if ($storedPath !== null) {
            Storage::disk('public')->delete($storedPath);
        }
    }

    public function isManagedPath(?string $path): bool
    {
        return $path !== null && $this->storedPath($path) !== null;
    }

    public function publicUrl(?string $path): ?string
    {
        $storedPath = $path === null ? null : $this->storedPath($path);

        return $storedPath === null ? $path : Storage::disk('public')->url($storedPath);
    }

    private function storedPath(string $path): ?string
    {
        if (preg_match('#^/storage/products/[a-f0-9]{32}\.(jpg|png|webp)$#', $path) !== 1) {
            return null;
        }

        return substr($path, strlen('/storage/'));
    }
}
