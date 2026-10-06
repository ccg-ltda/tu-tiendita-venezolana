<?php

namespace App\Services;

use App\Promotions\ProductPromotionNormalizer;
use App\Promotions\ProductPromotionPriceResolver;
use App\Promotions\PromotionContractException;
use DateTimeImmutable;
use DateTimeZone;

final class ProductPromotionAdminService
{
    private const BOGOTA = 'America/Bogota';

    public function __construct(
        private readonly CatalogSnapshotStore $catalog,
        private readonly GoogleSheetsPromotionStore $promotions,
        private readonly ProductPromotionPriceResolver $prices,
        private readonly CatalogPromotionSnapshotService $promotionSnapshot,
    ) {}

    /** @return array{product_id:int,promotion:array<string,mixed>|null,status:string,pricing:array<string,mixed>} */
    public function show(int $productId): array
    {
        $product = $this->product($productId);
        $promotion = $this->promotions->findByProductId($productId);

        return $this->view($product, $promotion);
    }

    /** @return list<array{product:array{id:int,name:string,price:int,active:bool},promotion:array<string,mixed>,status:string,pricing:array<string,mixed>}> */
    public function list(): array
    {
        try {
            $products = $this->catalog->read();
        } catch (\Throwable $exception) {
            throw new ProductSheetsException(503, 'CATALOG_UNAVAILABLE', $exception, 'El catÃ¡logo no estÃ¡ disponible.');
        }

        $byProductId = [];
        foreach ($products as $product) {
            $byProductId[$product['product_id']] = $product;
        }

        $rows = [];
        foreach ($this->promotions->all() as $promotion) {
            $product = $byProductId[$promotion['product_id']] ?? null;
            if ($product === null) {
                throw new ProductSheetsException(502, 'PROMOTION_PRODUCT_NOT_FOUND', null, 'Una promociÃ³n apunta a un producto inexistente.');
            }
            $view = $this->view($product, $promotion);
            $rows[] = [
                'product' => [
                    'id' => $product['product_id'],
                    'name' => $product['name'],
                    'price' => $product['price_cop'],
                    'active' => $product['active'],
                ],
                'promotion' => $view['promotion'],
                'status' => $view['status'],
                'pricing' => $view['pricing'],
            ];
        }

        usort($rows, static fn (array $left, array $right): int => strnatcasecmp($left['product']['name'], $right['product']['name']));

        return $rows;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function save(int $productId, array $input): array
    {
        $product = $this->product($productId);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        try {
            $candidate = ProductPromotionNormalizer::normalize([
                'product_id' => $productId,
                'active' => $input['active'],
                'discount_type' => $input['discount_type'],
                'discount_value' => $input['discount_value'],
                'starts_at' => $input['starts_at'] ?? null,
                'ends_at' => $input['ends_at'] ?? null,
                'updated_at' => $now->format('Y-m-d\\TH:i:s.v\\Z'),
                'revision' => 1,
            ]);
            // Validate the commercial amount even for an inactive saved configuration.
            $this->prices->resolve($product['price_cop'], array_replace($candidate, ['active' => true, 'starts_at' => null, 'ends_at' => null]), $now);
        } catch (PromotionContractException $exception) {
            throw new ProductSheetsException(422, 'INVALID_PROMOTION', $exception, $exception->getMessage());
        }
        $saved = $this->promotions->save($candidate, $input['expected_revision'] ?? null);
        try {
            $this->promotionSnapshot->refreshCurrent();
            $catalogRefreshed = true;
        } catch (\Throwable) {
            $catalogRefreshed = false;
        }

        return [...$this->view($product, $saved), 'catalog_refreshed' => $catalogRefreshed];
    }

    /** @param array<string,mixed> $product @param array<string,mixed>|null $promotion @return array{product_id:int,promotion:array<string,mixed>|null,status:string,pricing:array<string,mixed>} */
    private function view(array $product, ?array $promotion): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        try {
            $pricing = $this->prices->resolve($product['price_cop'], $promotion, $now);
        } catch (PromotionContractException $exception) {
            throw new ProductSheetsException(502, 'INVALID_PROMOTION_PRICE', $exception, 'La promoción guardada no es compatible con el precio base actual.');
        }

        return ['product_id' => $product['product_id'], 'promotion' => $promotion, 'status' => $this->status($promotion, $now), 'pricing' => $pricing];
    }

    /** @return array<string,mixed> */
    private function product(int $productId): array
    {
        try {
            foreach ($this->catalog->read() as $product) {
                if ($product['product_id'] === $productId) {
                    return $product;
                }
            }
        } catch (\Throwable $exception) {
            throw new ProductSheetsException(503, 'CATALOG_UNAVAILABLE', $exception, 'El catálogo no está disponible.');
        }

        throw new ProductSheetsException(404, 'PRODUCT_NOT_FOUND', null, 'El producto no existe.');
    }

    /** @param array<string,mixed>|null $promotion */
    private function status(?array $promotion, DateTimeImmutable $now): string
    {
        if ($promotion === null) return 'NONE';
        if (! $promotion['active']) return 'INACTIVE';
        $bogotaNow = $now->setTimezone(new DateTimeZone(self::BOGOTA));
        if ($promotion['starts_at'] !== null && $bogotaNow < new DateTimeImmutable($promotion['starts_at'])) return 'SCHEDULED';
        if ($promotion['ends_at'] !== null && $bogotaNow > new DateTimeImmutable($promotion['ends_at'])) return 'EXPIRED';

        return 'ACTIVE';
    }
}
