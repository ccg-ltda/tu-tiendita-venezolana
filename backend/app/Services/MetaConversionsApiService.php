<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class MetaConversionsApiService
{
    /**
     * @param array{name?: ?string, phone?: ?string, city?: ?string, region?: ?string, num_items?: int} $customer
     *        Datos extra del comprador (opcional). Van cifrados (SHA-256) y ayudan a que Meta
     *        reconozca a más compradores (mejor "calidad de coincidencia" del evento).
     */
    public function sendPurchase(string $reference, int $total, ?string $email, int $eventTime, array $customer = []): void
    {
        $pixelId = config('services.meta_conversions.pixel_id');
        $token = config('services.meta_conversions.access_token');
        $version = config('services.meta_conversions.api_version');

        if (! is_string($pixelId) || ! preg_match('/^[0-9]+$/D', $pixelId)
            || ! is_string($token) || trim($token) === ''
            || ! is_string($version) || ! preg_match('/^v[0-9]+\.[0-9]+$/D', $version)) {
            Log::warning('Meta purchase event could not be sent: configuration unavailable.');
            return;
        }

        $normalizedEmail = is_string($email) ? strtolower(trim($email)) : '';
        if ($normalizedEmail === '') {
            Log::warning('Meta purchase event could not be sent: customer email unavailable.');
            return;
        }

        try {
            $response = Http::acceptJson()->withToken($token)->connectTimeout(2)->timeout(5)
                ->post("https://graph.facebook.com/{$version}/{$pixelId}/events", [
                    'data' => [[
                        'event_name' => 'Purchase',
                        'event_time' => $eventTime,
                        'event_id' => $reference,
                        'action_source' => 'website',
                        'user_data' => $this->userData($normalizedEmail, $customer),
                        'custom_data' => array_filter(['currency' => 'COP', 'value' => $total, 'order_id' => $reference,
                            'num_items' => isset($customer['num_items']) ? (int) $customer['num_items'] : null], static fn ($v) => $v !== null),
                    ]],
                ]);

            if (! $response->successful()) {
                Log::warning('Meta purchase event could not be sent.', ['http_status' => $response->status()]);
            }
        } catch (\Throwable) {
            Log::warning('Meta purchase event could not be sent.');
        }
    }

    /** Datos del comprador normalizados y cifrados como pide Meta (minúsculas, sin espacios, SHA-256). */
    private function userData(string $email, array $customer): array
    {
        $sha = static fn (string $v): string => hash('sha256', $v);
        $clean = static fn (?string $v): string => preg_replace('/[^\p{L}]/u', '', mb_strtolower(trim((string) $v))) ?? '';
        $data = ['em' => [$sha($email)], 'external_id' => [$sha($email)], 'country' => [$sha('co')]];

        $digits = preg_replace('/\D+/', '', (string) ($customer['phone'] ?? '')) ?? '';
        if (strlen($digits) === 10) $digits = '57'.$digits; // celular colombiano sin indicativo
        if (strlen($digits) >= 11) $data['ph'] = [$sha($digits)];

        $parts = preg_split('/\s+/', trim((string) ($customer['name'] ?? ''))) ?: [];
        $first = $clean($parts[0] ?? '');
        $last = $clean(count($parts) > 1 ? end($parts) : '');
        if ($first !== '') $data['fn'] = [$sha($first)];
        if ($last !== '') $data['ln'] = [$sha($last)];

        $city = $clean($customer['city'] ?? '');
        if ($city !== '') $data['ct'] = [$sha($city)];
        $region = $clean($customer['region'] ?? '');
        if ($region !== '') $data['st'] = [$sha($region)];

        return $data;
    }
}
