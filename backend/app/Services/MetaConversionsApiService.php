<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class MetaConversionsApiService
{
    public function sendPurchase(string $reference, int $total, ?string $email, int $eventTime): void
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
                        'user_data' => ['em' => [hash('sha256', $normalizedEmail)]],
                        'custom_data' => ['currency' => 'COP', 'value' => $total],
                    ]],
                ]);

            if (! $response->successful()) {
                Log::warning('Meta purchase event could not be sent.', ['http_status' => $response->status()]);
            }
        } catch (\Throwable) {
            Log::warning('Meta purchase event could not be sent.');
        }
    }
}
