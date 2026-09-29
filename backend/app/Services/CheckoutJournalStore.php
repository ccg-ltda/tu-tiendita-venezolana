<?php

namespace App\Services;

use App\Exceptions\CheckoutConsistencyException;

final class CheckoutJournalStore
{
    private const STAGES = ['PREPARED','ORDER_WRITTEN','ITEMS_WRITTEN','PRODUCTS_WRITTEN','FINALIZED'];
    private const MAX_BYTES = 8192;

    public function __construct(private readonly ?string $directory = null) {}

    /** @param list<array{product_id:int,row_number:int,inventory_before:int,inventory_after:int,revision_before:int,revision_after:int}> $inventoryPlan */
    public function createPrepared(string $idempotencyKeyHash, string $payloadHash, int $orderId, int $firstOrderItemId, int $itemCount, string $reference, string $reservationExpiresAt, array $inventoryPlan, array $orderItemRows, ?string $now = null): array
    {
        $timestamp = $now ?? now('UTC')->format('Y-m-d\\TH:i:s.v\\Z');
        $journal = ['version'=>1,'idempotency_key_hash'=>$idempotencyKeyHash,'payload_hash'=>$payloadHash,'order_id'=>$orderId,'first_order_item_id'=>$firstOrderItemId,'item_count'=>$itemCount,'reference'=>$reference,'reservation_expires_at'=>$reservationExpiresAt,'stage'=>'PREPARED','created_at'=>$timestamp,'updated_at'=>$timestamp,'inventory_plan'=>$inventoryPlan,'order_item_rows'=>$orderItemRows];
        $this->validate($journal, $idempotencyKeyHash);
        if ($this->exists($idempotencyKeyHash)) throw new CheckoutConsistencyException;
        $this->write($idempotencyKeyHash, $journal);
        return $journal;
    }

    public function load(string $idempotencyKeyHash): ?array
    {
        $path = $this->path($idempotencyKeyHash);
        if (! is_file($path)) return null;
        $contents = @file_get_contents($path);
        if (! is_string($contents)) throw new CheckoutConsistencyException;
        try { $journal = json_decode($contents, true, 512, JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new CheckoutConsistencyException; }
        if (! is_array($journal)) throw new CheckoutConsistencyException;
        $this->validate($journal, $idempotencyKeyHash);
        return $journal;
    }

    public function advanceStage(string $idempotencyKeyHash, string $stage, ?string $now = null): array
    {
        $journal = $this->load($idempotencyKeyHash);
        if ($journal === null || ! in_array($stage, self::STAGES, true) || array_search($stage, self::STAGES, true) !== array_search($journal['stage'], self::STAGES, true) + 1) throw new CheckoutConsistencyException;
        $journal['stage'] = $stage;
        $journal['updated_at'] = $now ?? now('UTC')->format('Y-m-d\\TH:i:s.v\\Z');
        $this->validate($journal, $idempotencyKeyHash);
        $this->write($idempotencyKeyHash, $journal);
        return $journal;
    }

    public function markFinalized(string $idempotencyKeyHash, ?string $now = null): array
    {
        return $this->advanceStage($idempotencyKeyHash, 'FINALIZED', $now);
    }

    public function delete(string $idempotencyKeyHash): void
    {
        $path = $this->path($idempotencyKeyHash);
        if (is_file($path) && ! @unlink($path)) throw new CheckoutConsistencyException;
    }

    public function exists(string $idempotencyKeyHash): bool
    {
        return is_file($this->path($idempotencyKeyHash));
    }

    private function directory(): string
    {
        $directory = $this->directory ?? storage_path('app/private/checkout/journal');
        if (! is_dir($directory) && ! @mkdir($directory, 0750, true) && ! is_dir($directory)) throw new CheckoutConsistencyException;
        return $directory;
    }

    private function path(string $idempotencyKeyHash): string
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $idempotencyKeyHash) !== 1) throw new CheckoutConsistencyException;
        return $this->directory().DIRECTORY_SEPARATOR.$idempotencyKeyHash.'.json';
    }

    private function write(string $idempotencyKeyHash, array $journal): void
    {
        try { $json = json_encode($journal, JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new CheckoutConsistencyException; }
        if (strlen($json) > self::MAX_BYTES) throw new CheckoutConsistencyException;
        $path = $this->path($idempotencyKeyHash);
        $temporary = tempnam($this->directory(), '.journal-');
        if ($temporary === false) throw new CheckoutConsistencyException;
        $handle = @fopen($temporary, 'wb');
        if ($handle === false) throw new CheckoutConsistencyException;
        try {
            if (@fwrite($handle, $json) !== strlen($json) || ! @fflush($handle)) throw new CheckoutConsistencyException;
            if (function_exists('fsync') && ! @fsync($handle)) throw new CheckoutConsistencyException;
        } finally { @fclose($handle); }
        if (! @rename($temporary, $path)) { @unlink($temporary); throw new CheckoutConsistencyException; }
    }

    private function validate(array $journal, string $keyHash): void
    {
        $required = ['version','idempotency_key_hash','payload_hash','order_id','first_order_item_id','item_count','reference','reservation_expires_at','stage','created_at','updated_at','inventory_plan','order_item_rows'];
        if (array_keys($journal) !== $required || $journal['version'] !== 1 || $journal['idempotency_key_hash'] !== $keyHash || preg_match('/^[0-9a-f]{64}$/D', $keyHash) !== 1 || ! is_string($journal['payload_hash']) || preg_match('/^[0-9a-f]{64}$/D', $journal['payload_hash']) !== 1 || ! $this->positive($journal['order_id']) || ! $this->positive($journal['first_order_item_id']) || ! $this->positive($journal['item_count']) || $journal['item_count'] > 50 || ! is_string($journal['reference']) || $journal['reference'] === '' || strlen($journal['reference']) > 120 || ! $this->timestamp($journal['reservation_expires_at']) || ! in_array($journal['stage'], self::STAGES, true) || ! $this->timestamp($journal['created_at']) || ! $this->timestamp($journal['updated_at']) || ! is_array($journal['inventory_plan']) || count($journal['inventory_plan']) !== $journal['item_count'] || !is_array($journal['order_item_rows'])||count($journal['order_item_rows'])!==$journal['item_count']) throw new CheckoutConsistencyException;
        if (strtotime($journal['created_at']) > strtotime($journal['updated_at'])) throw new CheckoutConsistencyException;
        $products=[];
        foreach ($journal['inventory_plan'] as $plan) {
            if (! is_array($plan) || array_keys($plan) !== ['product_id','row_number','inventory_before','inventory_after','revision_before','revision_after'] || ! $this->positive($plan['product_id']) || ! $this->positive($plan['row_number']) || ! $this->nonNegative($plan['inventory_before']) || ! $this->nonNegative($plan['inventory_after']) || ! $this->positive($plan['revision_before']) || ! $this->positive($plan['revision_after']) || $plan['inventory_after'] > $plan['inventory_before'] || $plan['revision_after'] !== $plan['revision_before'] + 1 || isset($products[$plan['product_id']])) throw new CheckoutConsistencyException;
            $products[$plan['product_id']] = true;
        }
        foreach($journal['order_item_rows'] as $index=>$row)if(!is_array($row)||count($row)!==7||(int)$row[0]!==$journal['first_order_item_id']+$index||(int)$row[1]!==$journal['order_id']||(int)$row[2]!==$journal['inventory_plan'][$index]['product_id']||(int)$row[5]!==$journal['inventory_plan'][$index]['inventory_before']-$journal['inventory_plan'][$index]['inventory_after']||(int)$row[4]<1||!is_string($row[3])||trim($row[3])==='')throw new CheckoutConsistencyException;
    }

    private function positive(mixed $value): bool { return is_int($value) && $value >= 1 && $value <= 2147483647; }
    private function nonNegative(mixed $value): bool { return is_int($value) && $value >= 0 && $value <= 2147483647; }
    private function timestamp(mixed $value): bool { if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $value) !== 1) return false; try { return (new \DateTimeImmutable($value))->format('Y-m-d\\TH:i:s.v\\Z') === $value; } catch (\Throwable) { return false; } }
}
