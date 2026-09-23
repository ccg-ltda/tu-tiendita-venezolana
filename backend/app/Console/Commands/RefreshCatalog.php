<?php

namespace App\Console\Commands;

use App\Services\CatalogSnapshotException;
use App\Services\CatalogSnapshotStore;
use App\Repositories\ProductSheetsRepository;
use App\Services\ProductSheetsException;
use App\Services\ProductOutboxStore;
use App\Products\ProductNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshCatalog extends Command
{
    protected $signature = 'products:refresh-catalog';
    protected $description = 'Fetches the product catalog and safely refreshes the local snapshot.';

    public function handle(ProductSheetsRepository $products, CatalogSnapshotStore $snapshot, ProductOutboxStore $outbox): int
    {
        try {
            $freshProducts = $products->all();
            $byId = [];
            foreach ($freshProducts as $product) $byId[$product['product_id']] = $product;
            foreach (app()->environment('testing') ? [] : $outbox->all() as $operation) {
                $pending = ProductNormalizer::normalize($operation['product'] ?? null);
                if ($pending === null) continue;
                $remote = $byId[$pending['product_id']] ?? null;
                if ($remote === null || ($remote['revision'] ?? 0) < $pending['revision']) $byId[$pending['product_id']] = $pending;
            }
            $freshProducts = array_values($byId);
            usort($freshProducts, static fn (array $a, array $b): int => $a['product_id'] <=> $b['product_id']);
            $document = $snapshot->writeAtomically($freshProducts);
            $snapshot->replaceCache($document['products']);

            Log::info('Catalog snapshot refreshed.', [
                'product_count' => $document['product_count'],
                'generated_at' => $document['generated_at'],
            ]);
            $this->info("Catalog refreshed: {$document['product_count']} products.");

            return self::SUCCESS;
        } catch (ProductSheetsException $exception) {
            Log::warning('Catalog refresh failed while fetching upstream products.', ['status' => $exception->status()]);
        } catch (CatalogSnapshotException $exception) {
            Log::warning('Catalog refresh failed while writing the snapshot.', ['reason' => $this->safeReason($exception)]);
        } catch (\Throwable $exception) {
            Log::error('Catalog refresh failed unexpectedly.', ['exception' => $exception::class, 'message' => $this->safeReason($exception)]);
        }

        $this->error('Catalog refresh failed. The last valid catalog remains available.');

        return self::FAILURE;
    }

    private function safeReason(\Throwable $exception): string
    {
        return match ($exception->getMessage()) {
            'Catalog snapshot is invalid.' => 'invalid_snapshot',
            'Catalog snapshot could not be written safely.' => 'write_failure',
            default => 'snapshot_failure',
        };
    }
}
