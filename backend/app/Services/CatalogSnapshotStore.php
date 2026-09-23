<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Products\ProductNormalizer;

class CatalogSnapshotStore
{
    public const CACHE_KEY = 'products.catalog.snapshot.v1';

    private const SCHEMA_VERSION = 2;

    private const FILE_NAME = 'products.json';

    private readonly string $directory;

    public function __construct(?string $directory = null)
    {
        // The production location is fixed and never derives from a request.
        $this->directory = $directory ?? storage_path('app/private/catalog');
    }

    /**
     * @return list<array{product_id: int, category: string, subcategory: string, name: string, presentation: string, price_cop: int, inventory: int, active: bool, image_path: string|null, legacy_img: string|null}>
     *
     * @throws CatalogSnapshotException
     */
    public function read(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            try {
                return $this->validateProducts($cached);
            } catch (CatalogSnapshotException) {
                Cache::forget(self::CACHE_KEY);
                $this->reportFailure('invalid_cache');
            }
        }

        $products = $this->readSnapshot();
        Cache::forever(self::CACHE_KEY, $products);

        return $products;
    }

    public function exists(): bool
    {
        $path = $this->snapshotPath();

        return is_file($path) && ! is_link($path);
    }

    /**
     * @param array<int, mixed> $products
     * @return array{schema_version: int, generated_at: string, product_count: int, products: list<array{product_id: int, category: string, subcategory: string, name: string, presentation: string, price_cop: int, inventory: int, active: bool, image_path: string|null, legacy_img: string|null}>}
     *
     * @throws CatalogSnapshotException
     */
    public function writeAtomically(array $products): array
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => now('UTC')->format('Y-m-d\\TH:i:s.v\\Z'),
            'product_count' => count($products),
            'products' => $products,
        ];
        $payload = $this->validatePayload($payload);

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $payload = $this->validatePayload($decoded);
        } catch (\JsonException $exception) {
            $this->reportFailure('encoding_failed');

            throw new CatalogSnapshotException('Catalog snapshot encoding failed.', previous: $exception);
        }

        $temporaryPath = null;
        try {
            $this->ensureSafeDirectory();
            $temporaryPath = $this->temporaryPath();

            if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
                throw new CatalogSnapshotException('Catalog snapshot temporary write failed.');
            }

            @chmod($temporaryPath, 0640);
            $this->replaceFile($temporaryPath, $this->snapshotPath());
            $temporaryPath = null;

            return $payload;
        } catch (CatalogSnapshotException $exception) {
            $this->reportFailure('write_failed');

            throw $exception;
        } catch (\Throwable $exception) {
            $this->reportFailure('write_failed');

            throw new CatalogSnapshotException('Catalog snapshot write failed.', previous: $exception);
        } finally {
            if ($temporaryPath !== null && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    /** @param array<int, mixed> $products */
    public function replaceCache(array $products): void
    {
        Cache::forever(self::CACHE_KEY, $this->validateProducts($products));
    }

    /** @param array<string,mixed> $product @throws CatalogSnapshotException */
    public function patchProduct(array $product, bool $mustExist): void
    {
        $incoming = ProductNormalizer::snapshot($product);
        if ($incoming === null) throw new CatalogSnapshotException('Catalog snapshot products are invalid.');
        $products = $this->readSnapshot(); $found = false;
        foreach ($products as $index => $existing) if ($existing['product_id'] === $incoming['product_id']) { $products[$index] = $incoming; $found = true; }
        if ($mustExist && ! $found) throw new CatalogSnapshotException('Catalog snapshot product is missing.');
        if (! $mustExist && $found) throw new CatalogSnapshotException('Catalog snapshot product already exists.');
        if (! $mustExist) $products[] = $incoming;
        usort($products, static fn (array $a, array $b): int => $a['product_id'] <=> $b['product_id']);
        $document = $this->writeAtomically($products); $this->replaceCache($document['products']);
    }

    /**
     * @return list<array{product_id: int, category: string, subcategory: string, name: string, presentation: string, price_cop: int, inventory: int, active: bool, image_path: string|null, legacy_img: string|null}>
     */
    private function readSnapshot(): array
    {
        $path = $this->snapshotPath();
        if (! $this->exists()) {
            $this->reportFailure('missing_snapshot');

            throw new CatalogSnapshotException('Catalog snapshot is unavailable.');
        }

        $contents = @file_get_contents($path);
        if (! is_string($contents)) {
            $this->reportFailure('unreadable_snapshot');

            throw new CatalogSnapshotException('Catalog snapshot is unavailable.');
        }

        try {
            $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

            return $this->validatePayload($payload)['products'];
        } catch (\JsonException|CatalogSnapshotException) {
            $this->reportFailure('invalid_snapshot');

            throw new CatalogSnapshotException('Catalog snapshot is unavailable.');
        }
    }

    /** @param mixed $payload
     * @return array{schema_version: int, generated_at: string, product_count: int, products: list<array{product_id: int, category: string, subcategory: string, name: string, presentation: string, price_cop: int, inventory: int, active: bool, image_path: string|null, legacy_img: string|null}>}
     */
    private function validatePayload(mixed $payload): array
    {
        if (! is_array($payload)
            || ($payload['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || ! $this->validTimestamp($payload['generated_at'] ?? null)
            || ! is_int($payload['product_count'] ?? null) || $payload['product_count'] < 0
            || ! is_array($payload['products'] ?? null)
            || $payload['product_count'] !== count($payload['products'])) {
            throw new CatalogSnapshotException('Catalog snapshot schema is invalid.');
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => $payload['generated_at'],
            'product_count' => $payload['product_count'],
            'products' => $this->validateProducts($payload['products']),
        ];
    }

    /** @param array<int, mixed> $products
     * @return list<array{product_id: int, category: string, subcategory: string, name: string, presentation: string, price_cop: int, inventory: int, active: bool, image_path: string|null, legacy_img: string|null}>
     */
    private function validateProducts(array $products): array
    {
        $normalized = [];
        $ids = [];
        foreach ($products as $product) {
            $snapshotProduct = ProductNormalizer::snapshot($product);
            if ($snapshotProduct === null || isset($ids[$snapshotProduct['product_id']])) {
                throw new CatalogSnapshotException('Catalog snapshot products are invalid.');
            }

            $ids[$snapshotProduct['product_id']] = true;
            $normalized[] = $snapshotProduct;
        }

        return $normalized;
    }

    private function validTimestamp(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $value) !== 1) {
            return false;
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d\\TH:i:s.v\\Z') === $value;
        } catch (\Throwable) {
            return false;
        }
    }

    private function ensureSafeDirectory(): void
    {
        $privateRoot = storage_path('app/private');
        if (is_link($privateRoot) || is_link($this->directory)) {
            throw new CatalogSnapshotException('Catalog snapshot directory is unsafe.');
        }

        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0750, true) && ! is_dir($this->directory)) {
            throw new CatalogSnapshotException('Catalog snapshot directory cannot be created.');
        }

        if (is_link($this->directory)) {
            throw new CatalogSnapshotException('Catalog snapshot directory is unsafe.');
        }
    }

    private function replaceFile(string $temporaryPath, string $snapshotPath): void
    {
        if (! file_exists($snapshotPath)) {
            if (! @rename($temporaryPath, $snapshotPath)) {
                throw new CatalogSnapshotException('Catalog snapshot replacement failed.');
            }

            return;
        }

        // Linux replaces atomically here. Windows may reject overwrite, so use a
        // same-directory backup and restore path without ever writing in place.
        if (@rename($temporaryPath, $snapshotPath)) {
            return;
        }

        $backupPath = $snapshotPath.'.backup.'.bin2hex(random_bytes(8));
        if (! @rename($snapshotPath, $backupPath)) {
            throw new CatalogSnapshotException('Catalog snapshot backup failed.');
        }

        try {
            if (! @rename($temporaryPath, $snapshotPath)) {
                throw new CatalogSnapshotException('Catalog snapshot replacement failed.');
            }

            @chmod($snapshotPath, 0640);
            @unlink($backupPath);
        } catch (\Throwable $exception) {
            if (! file_exists($snapshotPath) && is_file($backupPath)) {
                @rename($backupPath, $snapshotPath);
            }

            throw $exception;
        }
    }

    private function snapshotPath(): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.self::FILE_NAME;
    }

    private function temporaryPath(): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.'products.'.bin2hex(random_bytes(12)).'.tmp';
    }

    private function reportFailure(string $reason): void
    {
        Log::warning('Catalog snapshot operation failed.', ['component' => 'CatalogSnapshotStore', 'reason' => $reason]);
    }
}
