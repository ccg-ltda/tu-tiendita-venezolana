<?php

namespace App\Services;

class CatalogPromotionSnapshotService
{
    public function __construct(private readonly CatalogSnapshotStore $catalog, private readonly GoogleSheetsPromotionStore $promotions) {}

    /** @param list<array<string,mixed>> $products @return list<array<string,mixed>> */
    public function refresh(array $products): array
    {
        $byProductId = [];
        foreach ($this->promotions->all() as $promotion) $byProductId[$promotion['product_id']] = $promotion;
        foreach ($products as &$product) {
            unset($product['promotion']);
            if (isset($byProductId[$product['product_id']])) $product['promotion'] = $byProductId[$product['product_id']];
        }
        unset($product);
        $document = $this->catalog->writeAtomically($products);
        $this->catalog->replaceCache($document['products']);

        return $document['products'];
    }

    /** @return list<array<string,mixed>> */
    public function refreshCurrent(): array
    {
        return $this->refresh($this->catalog->read());
    }
}
