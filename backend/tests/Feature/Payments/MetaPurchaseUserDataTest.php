<?php

namespace Tests\Feature\Payments;

use App\Services\MetaConversionsApiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class MetaPurchaseUserDataTest extends TestCase
{
    public function test_purchase_sends_hashed_buyer_data_for_better_match_quality(): void
    {
        config(['services.meta_conversions.pixel_id' => '123456', 'services.meta_conversions.access_token' => 'test-token', 'services.meta_conversions.api_version' => 'v21.0']);
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);

        app(MetaConversionsApiService::class)->sendPurchase('TTV-20261110-9-ABC', 149250, ' Cliente@Correo.com ', 1794300000, [
            'name' => 'John Estiven Briñez Melo', 'phone' => '+57 316 684 3419', 'city' => 'Bogotá', 'region' => 'Bogotá D.C.', 'num_items' => 15,
        ]);

        Http::assertSent(function ($request): bool {
            $event = $request['data'][0];
            $u = $event['user_data'];
            $sha = static fn (string $v): string => hash('sha256', $v);

            return $event['event_id'] === 'TTV-20261110-9-ABC'
                && $u['em'] === [$sha('cliente@correo.com')]
                && $u['ph'] === [$sha('573166843419')]
                && $u['fn'] === [$sha('john')]
                && $u['ln'] === [$sha('melo')]
                && $u['ct'] === [$sha('bogotá')]
                && $u['country'] === [$sha('co')]
                && $event['custom_data'] === ['currency' => 'COP', 'value' => 149250, 'order_id' => 'TTV-20261110-9-ABC', 'num_items' => 15];
        });
    }

    public function test_purchase_without_extra_data_keeps_working_as_before(): void
    {
        config(['services.meta_conversions.pixel_id' => '123456', 'services.meta_conversions.access_token' => 'test-token', 'services.meta_conversions.api_version' => 'v21.0']);
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);

        app(MetaConversionsApiService::class)->sendPurchase('TTV-1', 1000, 'a@b.co', 1794300000);

        Http::assertSent(fn ($request): bool => $request['data'][0]['user_data']['em'] === [hash('sha256', 'a@b.co')]
            && ! isset($request['data'][0]['user_data']['ph']));
    }
}
