<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProductSheetsClient
{
    /**
     * Fetches and validates a fresh catalog from Apps Script.
     *
     * This method deliberately has no cache or snapshot side effects.
     *
     * @return list<array{product_id: int, category: string, subcategory: string, name: string, presentation: string, price_cop: int, inventory: int, active: bool, image_path: string|null, legacy_img: string|null, created_at: string, updated_at: string, revision: int}>
     *
     * @throws ProductSheetsException
     */
    public function fetchFreshProducts(): array
    {
        $url = config('services.apps_script.url');
        $apiKey = config('services.apps_script.api_key');

        if (! is_string($url) || $url === '' || ! is_string($apiKey) || $apiKey === '') {
            $this->reportFailure('configuration_unavailable');

            throw new ProductSheetsException(503);
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout(60)
                ->retry(3, 250)
                ->post($url, [
                    'action' => 'list_products',
                    'api_key' => $apiKey,
                ]);
        } catch (ConnectionException $exception) {
            $this->reportFailure('transport_exception', $this->transportContext($exception, $url, $apiKey));

            throw new ProductSheetsException($this->isTimeout($exception) ? 504 : 503);
        } catch (\Throwable $exception) {
            $this->reportFailure('transport_exception', [
                'exception_class' => $exception::class,
                'classification' => 'other',
                'message' => $this->sanitizeMessage($exception->getMessage(), $url, $apiKey),
            ]);

            throw new ProductSheetsException(502);
        }

        if (! $response->successful()) {
            $context = ['status' => $response->status()];
            $contentType = $response->header('Content-Type');

            if (is_string($contentType) && $contentType !== '') {
                $context['content_type'] = $this->sanitizeHeaderValue($contentType);
            }

            $this->reportFailure('upstream_http_error', $context);

            throw new ProductSheetsException(502);
        }

        try {
            $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->reportFailure('invalid_json');

            throw new ProductSheetsException(502);
        }

        if (! is_array($payload) || ($payload['ok'] ?? null) !== true || ! is_array($payload['data'] ?? null)) {
            $this->reportFailure(is_array($payload) && ($payload['ok'] ?? null) !== true ? 'upstream_not_ok' : 'invalid_data');

            throw new ProductSheetsException(502);
        }

        $products = [];
        $productIds = [];
        foreach ($payload['data'] as $sheetProduct) {
            $product = self::normalizeUpstreamProduct($sheetProduct);

            if ($product === null) {
                $this->reportFailure('invalid_product');

                throw new ProductSheetsException(502);
            }

            if (isset($productIds[$product['product_id']])) {
                $this->reportFailure('duplicate_product_id', ['product_id' => $product['product_id']]);

                throw new ProductSheetsException(502);
            }

            $productIds[$product['product_id']] = true;
            $products[] = $product;
        }

        return $products;
    }

    /** @param array<string,mixed> $product @return array<string,mixed> */
    public function createProduct(array $product): array { return $this->writeProduct('create_product', ['product' => $product]); }

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    public function updateProduct(int $productId, int $expectedRevision, array $changes): array { return $this->writeProduct('update_product', ['product_id' => $productId, 'expected_revision' => $expectedRevision, 'changes' => $changes]); }

    /** @return array<string,mixed> */
    public function setProductActive(int $productId, int $expectedRevision, bool $active): array { return $this->writeProduct('set_product_active', ['product_id' => $productId, 'expected_revision' => $expectedRevision, 'active' => $active]); }

    /**
     * @return array{product_id: int, category: string, subcategory: string, name: string, presentation: string, price_cop: int, inventory: int, active: bool, image_path: string|null, legacy_img: string|null, revision: int}|null
     */
    public static function normalizeSnapshotProduct(mixed $product): ?array
    {
        if (! is_array($product)) {
            return null;
        }

        $id = self::integerValue($product['product_id'] ?? null);
        $price = self::integerValue($product['price_cop'] ?? null);
        $inventory = self::integerValue($product['inventory'] ?? null);
        $revision = self::integerValue($product['revision'] ?? null);
        $active = self::booleanValue($product['active'] ?? null);

        if ($id === null || $id < 1 || $price === null || $price < 0 || $inventory === null || $inventory < 0 || $revision === null || $revision < 1 || $active === null) {
            return null;
        }

        foreach (['category', 'subcategory', 'name', 'presentation'] as $field) {
            if (! is_string($product[$field] ?? null) || trim($product[$field]) === '') {
                return null;
            }
        }

        foreach (['image_path', 'legacy_img'] as $field) {
            if (! is_string($product[$field] ?? null) && ($product[$field] ?? null) !== null) {
                return null;
            }
        }

        return [
            'product_id' => $id,
            'category' => $product['category'],
            'subcategory' => $product['subcategory'],
            'name' => $product['name'],
            'presentation' => $product['presentation'],
            'price_cop' => $price,
            'inventory' => $inventory,
            'active' => $active,
            'image_path' => $product['image_path'] ?? null,
            'legacy_img' => $product['legacy_img'] ?? null,
            'revision' => $revision,
        ];
    }

    /** @return array{product_id: int, category: string, subcategory: string, name: string, presentation: string, price_cop: int, inventory: int, active: bool, image_path: string|null, legacy_img: string|null, created_at: string, updated_at: string, revision: int}|null */
    public static function normalizeUpstreamProduct(mixed $product): ?array
    {
        $normalized = self::normalizeSnapshotProduct($product);
        $revision = is_array($product) ? self::integerValue($product['revision'] ?? null) : null;

        if ($normalized === null || $revision === null || $revision < 1
            || ! is_string($product['created_at'] ?? null) || trim($product['created_at']) === ''
            || ! is_string($product['updated_at'] ?? null) || trim($product['updated_at']) === '') {
            return null;
        }

        return [
            'product_id'=>$normalized['product_id'],'category'=>$normalized['category'],'subcategory'=>$normalized['subcategory'],'name'=>$normalized['name'],'presentation'=>$normalized['presentation'],'price_cop'=>$normalized['price_cop'],'inventory'=>$normalized['inventory'],'active'=>$normalized['active'],'image_path'=>$normalized['image_path'],'legacy_img'=>$normalized['legacy_img'],'created_at'=>$product['created_at'],'updated_at'=>$product['updated_at'],'revision'=>$normalized['revision'],
        ];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function writeProduct(string $action, array $data): array
    {
        $url = config('services.apps_script.url'); $apiKey = config('services.apps_script.api_key');
        if (!is_string($url) || $url === '' || !is_string($apiKey) || $apiKey === '') throw new ProductSheetsException(503);
        try { $response = Http::acceptJson()->asJson()->connectTimeout(3)->timeout(30)->post($url, ['action'=>$action,'api_key'=>$apiKey,...$data]); }
        catch (ConnectionException $e) { $this->reportFailure('transport_exception', ['operation'=>$action, ...$this->transportContext($e, $url, $apiKey)]); throw new ProductSheetsException($this->isTimeout($e)?504:503); }
        catch (\Throwable $e) { $this->reportFailure('transport_exception', ['operation'=>$action, 'exception_class'=>$e::class, 'classification'=>'other', 'message'=>$this->sanitizeMessage($e->getMessage(), $url, $apiKey)]); throw new ProductSheetsException(502); }
        if (!$response->successful()) { $this->reportFailure('upstream_http_error', ['operation'=>$action, ...$this->responseContext($response)]); throw new ProductSheetsException(502); }
        try { $payload=json_decode($response->body(),true,512,JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new ProductSheetsException(502); }
        if (!is_array($payload) || !is_bool($payload['ok']??null)) throw new ProductSheetsException(502);
        if ($payload['ok']===false) { $code=is_array($payload['error']??null)&&is_string($payload['error']['code']??null)?$payload['error']['code']:null; throw new ProductSheetsException(match($code){'INVALID_REQUEST'=>422,'PRODUCT_NOT_FOUND'=>404,'REVISION_CONFLICT'=>409,'LOCK_TIMEOUT'=>503,default=>502},$code); }
        $product=self::normalizeUpstreamProduct($payload['data']['product']??null); if($product===null) throw new ProductSheetsException(502);
        return $product;
    }

    private static function integerValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/', $value) === 1 ? (int) $value : null;
    }

    private static function booleanValue(mixed $value): ?bool
    {
        return match ($value) {
            true, 1, '1', 'true' => true,
            false, 0, '0', 'false' => false,
            default => null,
        };
    }

    private function isTimeout(ConnectionException $exception): bool
    {
        $message = mb_strtolower($this->exceptionMessages($exception));

        return str_contains($message, 'timeout') || str_contains($message, 'timed out') || str_contains($message, 'curl error 28');
    }

    /** @return array<string, mixed> */
    private function transportContext(ConnectionException $exception, string $url, string $apiKey): array
    {
        $messages = $this->exceptionMessages($exception);
        $normalized = mb_strtolower($messages);

        return [
            'exception_class' => $exception::class,
            'classification' => match (true) {
                str_contains($normalized, 'timeout'), str_contains($normalized, 'timed out'), str_contains($normalized, 'curl error 28') => 'timeout',
                str_contains($normalized, 'could not resolve host'), str_contains($normalized, 'getaddrinfo') => 'DNS',
                str_contains($normalized, 'ssl'), str_contains($normalized, 'tls'), str_contains($normalized, 'certificate') => 'TLS',
                default => 'connection',
            },
            'message' => $this->sanitizeMessage($messages, $url, $apiKey),
        ];
    }

    private function exceptionMessages(\Throwable $exception): string
    {
        $messages = [];
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $messages[] = $current->getMessage();
        }

        return implode(' | ', $messages);
    }

    /** @param array<string, mixed> $context */
    private function reportFailure(string $reason, array $context = []): void
    {
        Log::warning('Product Sheets request failed.', ['component' => 'ProductSheetsClient', 'operation' => 'list_products', 'reason' => $reason, ...$context]);
    }

    /** @return array<string, mixed> */
    private function responseContext(\Illuminate\Http\Client\Response $response): array
    {
        $context = ['http_status' => $response->status()];
        $contentType = $response->header('Content-Type');
        if (is_string($contentType) && $contentType !== '') $context['content_type'] = $this->sanitizeHeaderValue($contentType);
        return $context;
    }

    private function sanitizeMessage(string $message, string $url, string $apiKey): string
    {
        $sanitized = str_replace([$url, $apiKey], ['[REDACTED]', '[REDACTED]'], $message);
        $sanitized = preg_replace('#https?://[^\s\]\[()<>"\']+#i', '[REDACTED]', $sanitized) ?? '';
        $sanitized = preg_replace('/\b(?:authorization|cookie|api[_-]?key|access_token|token)\s*[:=]\s*(?:bearer\s+)?[^\s,}\]]+/i', '[REDACTED]', $sanitized) ?? '';
        $sanitized = preg_replace('/\bBearer\s+[^\s,}\]]+/i', 'Bearer [REDACTED]', $sanitized) ?? '';

        return mb_strimwidth(trim(str_replace(["\r", "\n"], ' ', $sanitized)), 0, 500, '...') ?: 'unavailable';
    }

    private function sanitizeHeaderValue(string $value): string
    {
        return mb_strimwidth(trim(str_replace(["\r", "\n"], ' ', $value)), 0, 160, '...');
    }
}
