<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Private, encrypted read cache for administrator order data.
 *
 * Cache keys are only derived from validated numeric identifiers. The values are
 * encrypted before they reach the configured cache store, which is important for
 * the PII included in order details.
 */
class AdminOrdersCache
{
    public const FRESH_FOR_SECONDS = 60;

    public const FALLBACK_FOR_SECONDS = 900;

    private const VERSION = 1;

    /** @return array{data: array{orders: list<array{id: int, reference: string, status: string, customer_name: string, total: int, created_at: string}>, pagination: array{current_page: int, per_page: int, total: int, last_page: int}}, stale: bool, cached_at: string}|null */
    public function list(int $page, int $perPage): ?array
    {
        $this->assertListCoordinates($page, $perPage);

        return $this->read($this->listKey($page, $perPage), 'list', fn (mixed $data): array => $this->validateList($data));
    }

    /** @param array{orders: list<array{id: int, reference: string, status: string, customer_name: string, total: int, created_at: string}>, pagination: array{current_page: int, per_page: int, total: int, last_page: int}} $data */
    public function putList(int $page, int $perPage, array $data): void
    {
        $this->assertListCoordinates($page, $perPage);
        $validated = $this->validateList($data);
        if ($validated['pagination']['current_page'] !== $page || $validated['pagination']['per_page'] !== $perPage) {
            throw new \InvalidArgumentException('The order page does not match its cache key.');
        }

        $this->write($this->listKey($page, $perPage), 'list', $validated);
    }

    /** @return array{data: array{id: int, reference: string, status: string, customer_name: string, customer_email: string, customer_phone: string, customer_document: string, address: string, extra: string|null, city: string, region: string, postal: string|null, total: int, created_at: string, items: list<array{id: int, product_id: int, product_name: string, unit_price: int, quantity: int}>}, stale: bool, cached_at: string}|null */
    public function detail(int $orderId): ?array
    {
        $this->assertOrderId($orderId);

        return $this->read($this->detailKey($orderId), 'detail', fn (mixed $data): array => $this->validateDetail($data));
    }

    /** @param array{id: int, reference: string, status: string, customer_name: string, customer_email: string, customer_phone: string, customer_document: string, address: string, extra: string|null, city: string, region: string, postal: string|null, total: int, created_at: string, items: list<array{id: int, product_id: int, product_name: string, unit_price: int, quantity: int}>} $data */
    public function putDetail(int $orderId, array $data): void
    {
        $this->assertOrderId($orderId);
        $validated = $this->validateDetail($data);
        if ($validated['id'] !== $orderId) {
            throw new \InvalidArgumentException('The order detail does not match its cache key.');
        }

        $this->write($this->detailKey($orderId), 'detail', $validated);
    }

    /**
     * Keeps the last valid value available while ensuring the next read treats
     * it as stale. This deliberately does not make another upstream request.
     */
    public function markListStale(int $page, int $perPage): void
    {
        $this->markStale($this->listKey($page, $perPage), 'list', fn (mixed $data): array => $this->validateList($data));
    }

    public function markDetailStale(int $orderId): void
    {
        $this->markStale($this->detailKey($orderId), 'detail', fn (mixed $data): array => $this->validateDetail($data));
    }

    public function forgetList(int $page, int $perPage): void
    {
        Cache::forget($this->listKey($page, $perPage));
    }

    public function forgetDetail(int $orderId): void
    {
        Cache::forget($this->detailKey($orderId));
    }

    public function listCacheKey(int $page, int $perPage): string
    {
        $this->assertListCoordinates($page, $perPage);

        return $this->listKey($page, $perPage);
    }

    public function detailCacheKey(int $orderId): string
    {
        $this->assertOrderId($orderId);

        return $this->detailKey($orderId);
    }

    /** Serialize a refresh for one internal cache key using the file driver. */
    public function withRefreshLock(string $scope, callable $callback): mixed
    {
        // Keep synchronization locks beside Laravel's file-cache data. This
        // directory is already writable by the web runtime; the private data
        // directory may have been created by a different CLI account.
        $directory = storage_path('framework/cache/admin-orders-locks');
        Log::debug('admin_orders_refresh_lock_attempt', ['scope' => $scope]);
        if (! is_dir($directory) && ! @mkdir($directory, 0750, true) && ! is_dir($directory)) {
            Log::warning('admin_orders_refresh_lock_failed', ['scope' => $scope, 'reason' => 'directory_unavailable']);
            throw new \RuntimeException('Order refresh lock directory unavailable.');
        }
        $path = $directory.DIRECTORY_SEPARATOR.hash('sha256', $scope).'.lock';
        $handle = @fopen($path, 'c');
        if ($handle === false || ! @flock($handle, LOCK_EX)) {
            if (is_resource($handle)) @fclose($handle);
            Log::warning('admin_orders_refresh_lock_failed', ['scope' => $scope, 'reason' => 'lock_unavailable']);
            throw new \RuntimeException('Order refresh lock unavailable.');
        }
        Log::debug('admin_orders_refresh_lock_acquired', ['scope' => $scope]);
        try {
            return $callback();
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /** @param callable(mixed): array<string, mixed> $validator */
    private function read(string $key, string $kind, callable $validator): ?array
    {
        $encrypted = Cache::get($key);
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)
                || ($decoded['version'] ?? null) !== self::VERSION
                || ($decoded['kind'] ?? null) !== $kind
                || ! is_string($decoded['cached_at'] ?? null)
                || ! is_string($decoded['fresh_until'] ?? null)
                || ! is_string($decoded['fallback_until'] ?? null)
                || ! array_key_exists('data', $decoded)) {
                throw new \UnexpectedValueException('Invalid cached order envelope.');
            }

            $cachedAt = $this->timestamp($decoded['cached_at']);
            $freshUntil = $this->timestamp($decoded['fresh_until']);
            $fallbackUntil = $this->timestamp($decoded['fallback_until']);
            if ($cachedAt === null || $freshUntil === null || $fallbackUntil === null || $freshUntil > $fallbackUntil || now()->greaterThan($fallbackUntil)) {
                throw new \UnexpectedValueException('Expired cached order envelope.');
            }

            $data = $validator($decoded['data']);

            return [
                'data' => $data,
                'stale' => now()->greaterThan($freshUntil),
                'cached_at' => $decoded['cached_at'],
            ];
        } catch (\Throwable) {
            // A corrupt or undecryptable cache entry is never useful and must not
            // be retained or reported with its potentially sensitive contents.
            Cache::forget($key);

            return null;
        }
    }

    /** @param array<string, mixed> $data */
    private function write(string $key, string $kind, array $data): void
    {
        $now = now()->utc();
        $envelope = [
            'version' => self::VERSION,
            'kind' => $kind,
            'cached_at' => $now->format('Y-m-d\\TH:i:s.v\\Z'),
            'fresh_until' => $now->copy()->addSeconds(self::FRESH_FOR_SECONDS)->format('Y-m-d\\TH:i:s.v\\Z'),
            'fallback_until' => $now->copy()->addSeconds(self::FALLBACK_FOR_SECONDS)->format('Y-m-d\\TH:i:s.v\\Z'),
            'data' => $data,
        ];

        Cache::put(
            $key,
            Crypt::encryptString(json_encode($envelope, JSON_THROW_ON_ERROR)),
            $now->copy()->addSeconds(self::FALLBACK_FOR_SECONDS),
        );
    }

    /** @param callable(mixed): array<string, mixed> $validator */
    private function markStale(string $key, string $kind, callable $validator): void
    {
        $entry = $this->read($key, $kind, $validator);
        if ($entry === null) {
            return;
        }

        $encrypted = Cache::get($key);
        if (! is_string($encrypted)) {
            return;
        }

        try {
            $envelope = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($envelope) || ! is_string($envelope['fallback_until'] ?? null)) {
                throw new \UnexpectedValueException('Invalid cached order envelope.');
            }
            $fallbackUntil = $this->timestamp($envelope['fallback_until']);
            if ($fallbackUntil === null || now()->greaterThan($fallbackUntil)) {
                throw new \UnexpectedValueException('Expired cached order envelope.');
            }

            $envelope['fresh_until'] = now()->utc()->subSecond()->format('Y-m-d\\TH:i:s.v\\Z');
            Cache::put($key, Crypt::encryptString(json_encode($envelope, JSON_THROW_ON_ERROR)), $fallbackUntil);
        } catch (\Throwable) {
            Cache::forget($key);
        }
    }

    /** @return array{orders: list<array{id: int, reference: string, status: string, customer_name: string, total: int, created_at: string}>, pagination: array{current_page: int, per_page: int, total: int, last_page: int}} */
    private function validateList(mixed $data): array
    {
        if (! is_array($data) || ! is_array($data['orders'] ?? null) || ! is_array($data['pagination'] ?? null)) {
            throw new \UnexpectedValueException('Invalid cached order list.');
        }
        $pagination = $data['pagination'];
        if (! $this->integer($pagination['current_page'] ?? null, 1, 2147483647)
            || ! $this->integer($pagination['per_page'] ?? null, 1, 100)
            || ! $this->integer($pagination['total'] ?? null, 0, 2147483647)
            || ! $this->integer($pagination['last_page'] ?? null, 1, 2147483647)
            || $pagination['last_page'] !== max(1, (int) ceil($pagination['total'] / $pagination['per_page']))) {
            throw new \UnexpectedValueException('Invalid cached order pagination.');
        }

        $orders = array_map(function (mixed $order): array {
            if (! is_array($order)
                || ! $this->integer($order['id'] ?? null, 1, 2147483647)
                || ! $this->text($order['reference'] ?? null, 1, 120)
                || ! $this->text($order['status'] ?? null, 1, 100)
                || (array_key_exists('payment_status', $order) && ! $this->text($order['payment_status'], 1, 100))
                || ! $this->text($order['customer_name'] ?? null, 1, 120)
                || ! $this->integer($order['total'] ?? null, 0, 2147483647)
                || $this->timestamp($order['created_at'] ?? null) === null) {
                throw new \UnexpectedValueException('Invalid cached order list item.');
            }

            return [
                'id' => $order['id'], 'reference' => $order['reference'], 'status' => $order['status'], ...(array_key_exists('payment_status', $order) ? ['payment_status' => $order['payment_status']] : []),
                'customer_name' => $order['customer_name'], 'total' => $order['total'], 'created_at' => $order['created_at'],
            ];
        }, $data['orders']);
        $ids = array_column($orders, 'id');
        if (count($orders) > $pagination['per_page'] || count($ids) !== count(array_unique($ids))) {
            throw new \UnexpectedValueException('Invalid cached order list identifiers.');
        }

        return ['orders' => $orders, 'pagination' => [
            'current_page' => $pagination['current_page'], 'per_page' => $pagination['per_page'],
            'total' => $pagination['total'], 'last_page' => $pagination['last_page'],
        ]];
    }

    /** @return array{id: int, reference: string, status: string, customer_name: string, customer_email: string, customer_phone: string, customer_document: string, address: string, extra: string|null, city: string, region: string, postal: string|null, total: int, created_at: string, items: list<array{id: int, product_id: int, product_name: string, unit_price: int, quantity: int}>} */
    private function validateDetail(mixed $data): array
    {
        if (! is_array($data)
            || ! $this->integer($data['id'] ?? null, 1, 2147483647)
            || ! $this->text($data['reference'] ?? null, 1, 120)
            || ! $this->text($data['status'] ?? null, 1, 100)
            || (array_key_exists('payment_status', $data) && ! $this->text($data['payment_status'], 1, 100))
            || (array_key_exists('reservation_status', $data) && ! $this->text($data['reservation_status'], 1, 100))
            || (array_key_exists('paid_at', $data) && ! $this->nullableText($data['paid_at'], 40))
            || (array_key_exists('payment', $data) && $data['payment'] !== null && ! is_array($data['payment']))
            || ! $this->text($data['customer_name'] ?? null, 1, 120)
            || ! $this->text($data['customer_email'] ?? null, 3, 254)
            || ! $this->text($data['customer_phone'] ?? null, 1, 50)
            || ! $this->text($data['customer_document'] ?? null, 1, 50)
            || ! $this->text($data['address'] ?? null, 1, 300)
            || ! $this->nullableText($data['extra'] ?? null, 300)
            || ! $this->text($data['city'] ?? null, 1, 100)
            || ! $this->text($data['region'] ?? null, 1, 100)
            || ! $this->nullableText($data['postal'] ?? null, 30)
            || ! $this->integer($data['total'] ?? null, 0, 2147483647)
            || $this->timestamp($data['created_at'] ?? null) === null
            || ! is_array($data['items'] ?? null)) {
            throw new \UnexpectedValueException('Invalid cached order detail.');
        }

        $items = array_map(function (mixed $item): array {
            if (! is_array($item)
                || ! $this->integer($item['id'] ?? null, 1, 2147483647)
                || ! $this->integer($item['product_id'] ?? null, 1, 2147483647)
                || ! $this->text($item['product_name'] ?? null, 1, 500)
                || ! $this->integer($item['unit_price'] ?? null, 0, 2147483647)
                || ! $this->integer($item['quantity'] ?? null, 1, 999)) {
                throw new \UnexpectedValueException('Invalid cached order item.');
            }
            return ['id' => $item['id'], 'product_id' => $item['product_id'], 'product_name' => $item['product_name'], 'unit_price' => $item['unit_price'], 'quantity' => $item['quantity']];
        }, $data['items']);
        $ids = array_column($items, 'id');
        if (count($ids) !== count(array_unique($ids))) {
            throw new \UnexpectedValueException('Invalid cached order item identifiers.');
        }

        return [
            'id' => $data['id'], 'reference' => $data['reference'], 'status' => $data['status'], ...(array_key_exists('payment_status', $data) ? ['payment_status' => $data['payment_status']] : []), ...(array_key_exists('reservation_status', $data) ? ['reservation_status' => $data['reservation_status']] : []), ...(array_key_exists('paid_at', $data) ? ['paid_at' => $data['paid_at']] : []), ...(array_key_exists('payment', $data) ? ['payment' => $data['payment']] : []),
            'customer_name' => $data['customer_name'], 'customer_email' => $data['customer_email'],
            'customer_phone' => $data['customer_phone'], 'customer_document' => $data['customer_document'],
            'address' => $data['address'], 'extra' => $data['extra'], 'city' => $data['city'],
            'region' => $data['region'], 'postal' => $data['postal'], 'total' => $data['total'],
            'created_at' => $data['created_at'], 'items' => $items,
        ];
    }

    private function listKey(int $page, int $perPage): string { return "admin.orders.list.v1:{$page}:{$perPage}"; }

    private function detailKey(int $orderId): string { return "admin.orders.detail.v1:{$orderId}"; }

    private function assertListCoordinates(int $page, int $perPage): void
    {
        if ($page < 1 || $page > 2147483647 || $perPage < 1 || $perPage > 100) throw new \InvalidArgumentException('Invalid order page cache coordinates.');
    }

    private function assertOrderId(int $orderId): void
    {
        if ($orderId < 1 || $orderId > 2147483647) throw new \InvalidArgumentException('Invalid order cache identifier.');
    }

    private function integer(mixed $value, int $min, int $max): bool { return is_int($value) && $value >= $min && $value <= $max; }

    private function text(mixed $value, int $min, int $max): bool { return is_string($value) && $value === trim($value) && strlen($value) >= $min && strlen($value) <= $max; }

    private function nullableText(mixed $value, int $max): bool { return $value === null || $this->text($value, 0, $max); }

    private function timestamp(mixed $value): ?\DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $value) !== 1) return null;
        try {
            $date = new \DateTimeImmutable($value);
            return $date->format('Y-m-d\\TH:i:s.v\\Z') === $value ? $date : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
