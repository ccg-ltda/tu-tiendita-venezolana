<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ProductImageStore
{
    private const DIRECTORY = 'products';

    public function store(UploadedFile $file): string
    {
        $mime = $file->getMimeType();
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

        if (! isset($extensions[$mime]) || @getimagesize($file->getRealPath()) === false) {
            throw new \InvalidArgumentException('Invalid product image.');
        }

        $filename = bin2hex(random_bytes(16)).'.'.$extensions[$mime];
        $storedPath = Storage::disk('public')->putFileAs(self::DIRECTORY, $file, $filename, 'public');

        if (! is_string($storedPath) || $storedPath === '') {
            throw new \RuntimeException('Product image could not be stored.');
        }

        return '/storage/'.$storedPath;
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
