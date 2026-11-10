<?php

namespace App\Support\Meta;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Sends approved purchases to the Meta Conversions API (server side).
 *
 * event_id = order reference, the same value the browser pushes as
 * transaction_id, so Meta deduplicates the browser and server Purchase.
 * Customer data is SHA-256 hashed before leaving the server, as Meta requires.
 */
class MetaConversionsClient
{
    public const CONTEXT_CACHE_PREFIX = 'meta_capi_ctx:';

    public static function configured(): bool
    {
        return is_string(config('services.meta.pixel_id')) && config('services.meta.pixel_id') !== ''
            && is_string(config('services.meta.capi_token')) && config('services.meta.capi_token') !== '';
    }

    /** Browser context captured when the payment was prepared (user agent, IP, _fbp, _fbc). */
    public static function rememberBrowserContext(string $reference, ?string $userAgent, ?string $ip, ?string $fbp, ?string $fbc): void
    {
        if (! self::configured()) return;
        Cache::put(self::CONTEXT_CACHE_PREFIX.$reference, array_filter([
            'client_user_agent' => $userAgent !== null ? substr($userAgent, 0, 500) : null,
            'client_ip_address' => $ip,
            'fbp' => self::cookie($fbp, '/^fb\.\d+\.\d+\.\d+$/'),
            'fbc' => self::cookie($fbc, '/^fb\.\d+\.\d+\..+$/'),
        ]), now()->addDays(3));
    }

    /**
     * @param array<string,mixed> $entry outbox payload of a META_COMPRA entry
     * @throws \RuntimeException when Meta does not accept the event (the outbox retries)
     */
    public function sendPurchase(array $entry): void
    {
        if (! self::configured()) throw new \RuntimeException('Meta Conversions API is not configured.');
        $reference = $entry['reference'] ?? null;
        if (! is_string($reference) || $reference === '') throw new \InvalidArgumentException('Purchase without reference.');

        $context = Cache::get(self::CONTEXT_CACHE_PREFIX.$reference);
        $context = is_array($context) ? $context : [];
        $items = is_array($entry['items'] ?? null) ? $entry['items'] : [];
        [$firstName, $lastName] = $this->splitName($entry['customer_name'] ?? null);

        $userData = array_filter([
            'em' => $this->hash($this->normalizeEmail($entry['customer_email'] ?? null)),
            'ph' => $this->hash($this->normalizePhone($entry['customer_phone'] ?? null)),
            'fn' => $this->hash($firstName),
            'ln' => $this->hash($lastName),
            'ct' => $this->hash($this->normalizeText($entry['city'] ?? null, true)),
            'st' => $this->hash($this->normalizeText($entry['region'] ?? null, true)),
            'country' => $this->hash('co'),
            'external_id' => $this->hash($this->normalizeEmail($entry['customer_email'] ?? null)),
            'client_user_agent' => $context['client_user_agent'] ?? null,
            'client_ip_address' => $context['client_ip_address'] ?? null,
            'fbp' => $context['fbp'] ?? null,
            'fbc' => $context['fbc'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');

        $event = [
            'event_name' => 'Purchase',
            'event_time' => $this->eventTime($entry['paid_at'] ?? null),
            'event_id' => $reference,
            'action_source' => 'website',
            'event_source_url' => 'https://tutienditavenezolana.com/',
            'user_data' => $userData,
            'custom_data' => [
                'currency' => 'COP',
                'value' => (int) ($entry['total_cop'] ?? 0),
                'order_id' => $reference,
                'content_type' => 'product',
                'content_ids' => array_values(array_map(static fn (array $item): string => (string) $item['product_id'], $items)),
                'contents' => array_values(array_map(static fn (array $item): array => [
                    'id' => (string) $item['product_id'],
                    'quantity' => (int) $item['quantity'],
                    'item_price' => (int) $item['unit_price_cop'],
                ], $items)),
                'num_items' => array_sum(array_map(static fn (array $item): int => (int) $item['quantity'], $items)),
            ],
        ];

        $body = ['data' => [$event]];
        $testCode = config('services.meta.test_event_code');
        if (is_string($testCode) && $testCode !== '') $body['test_event_code'] = $testCode;

        $url = sprintf('https://graph.facebook.com/%s/%s/events', config('services.meta.graph_version', 'v21.0'), rawurlencode((string) config('services.meta.pixel_id')));
        try {
            $response = Http::acceptJson()->connectTimeout(3)->timeout(8)
                // Token en el cuerpo (no en la URL) para que no quede en logs.
                ->post($url, [...$body, 'access_token' => (string) config('services.meta.capi_token')]);
        } catch (ConnectionException $exception) {
            throw new \RuntimeException('Meta Conversions API is unavailable.', previous: $exception);
        }
        if (! $response->successful()) {
            $message = $response->json('error.message');
            throw new \RuntimeException('Meta Conversions API rejected the event (HTTP '.$response->status().'): '.(is_string($message) ? substr($message, 0, 300) : 'sin detalle'));
        }
        Cache::forget(self::CONTEXT_CACHE_PREFIX.$reference);
    }

    private function eventTime(mixed $paidAt): int
    {
        $time = is_string($paidAt) && $paidAt !== '' ? strtotime($paidAt) : false;
        // Meta rejects events older than 7 days; outbox retries stay well inside that.
        return $time !== false && $time > time() - 6 * 86400 ? $time : time();
    }

    private function hash(?string $value): ?string
    {
        return $value === null || $value === '' ? null : hash('sha256', $value);
    }

    private function normalizeEmail(mixed $email): ?string
    {
        return is_string($email) && trim($email) !== '' ? strtolower(trim($email)) : null;
    }

    /** Colombian mobile numbers: 3XXXXXXXXX → 573XXXXXXXXX (digits only). */
    private function normalizePhone(mixed $phone): ?string
    {
        if (! is_string($phone)) return null;
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (preg_match('/^3\d{9}$/', $digits) === 1) return '57'.$digits;
        return preg_match('/^573\d{9}$/', $digits) === 1 ? $digits : null;
    }

    private function normalizeText(mixed $value, bool $stripSpaces = false): ?string
    {
        if (! is_string($value) || trim($value) === '') return null;
        $text = mb_strtolower(trim($value));
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = is_string($ascii) ? strtolower($ascii) : $text;
        $text = preg_replace($stripSpaces ? '/[^a-z]/' : '/[^a-z ]/', '', $text) ?? '';
        return $text !== '' ? $text : null;
    }

    /** @return array{0:?string,1:?string} */
    private function splitName(mixed $name): array
    {
        if (! is_string($name)) return [null, null];
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = $this->normalizeText($parts[0] ?? null, true);
        $last = count($parts) > 1 ? $this->normalizeText($parts[count($parts) - 1], true) : null;
        return [$first, $last];
    }

    private static function cookie(?string $value, string $pattern): ?string
    {
        return is_string($value) && strlen($value) <= 500 && preg_match($pattern, $value) === 1 ? $value : null;
    }
}
