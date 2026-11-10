<?php

namespace Tests\Feature\Payments;

use App\Support\Meta\MetaConversionsClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class MetaConversionsClientTest extends TestCase
{
    private function entry(): array
    {
        return [
            'notification_type' => 'META_COMPRA', 'recipient_kind' => 'meta', 'order_id' => 7,
            'reference' => 'TTV-ABC123', 'customer_name' => 'María José Pérez', 'customer_email' => ' Maria@Example.com ',
            'customer_phone' => '300 123 4567', 'city' => 'Bogotá', 'region' => 'Cundinamarca',
            'total_cop' => 45000, 'paid_at' => now('UTC')->toIso8601String(),
            'items' => [
                ['product_id' => 1, 'product_name' => 'Adobo', 'unit_price_cop' => 11250, 'quantity' => 2],
                ['product_id' => 9, 'product_name' => 'Harina PAN', 'unit_price_cop' => 7500, 'quantity' => 3],
            ],
        ];
    }

    private function configure(): void
    {
        config()->set('services.meta.pixel_id', '123456789');
        config()->set('services.meta.capi_token', 'test-token');
        config()->set('services.meta.test_event_code', 'TEST1');
        config()->set('services.meta.graph_version', 'v21.0');
    }

    public function test_it_is_disabled_without_configuration(): void
    {
        config()->set('services.meta.pixel_id', null);
        config()->set('services.meta.capi_token', null);
        $this->assertFalse(MetaConversionsClient::configured());
    }

    public function test_purchase_is_sent_hashed_with_reference_as_event_id(): void
    {
        $this->configure();
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);
        MetaConversionsClient::rememberBrowserContext('TTV-ABC123', 'Mozilla/5.0', '181.1.2.3', 'fb.1.1700000000000.123456789', 'not-a-valid-fbc');

        (new MetaConversionsClient())->sendPurchase($this->entry());

        Http::assertSent(function (Request $request): bool {
            $event = $request['data'][0];
            $user = $event['user_data'];
            return $request->url() === 'https://graph.facebook.com/v21.0/123456789/events'
                && $request['access_token'] === 'test-token'
                && $request['test_event_code'] === 'TEST1'
                && $event['event_name'] === 'Purchase'
                && $event['event_id'] === 'TTV-ABC123'
                && $event['action_source'] === 'website'
                && $event['custom_data']['value'] === 45000
                && $event['custom_data']['currency'] === 'COP'
                && $event['custom_data']['content_ids'] === ['1', '9']
                && $event['custom_data']['num_items'] === 5
                && $user['em'] === hash('sha256', 'maria@example.com')
                && $user['ph'] === hash('sha256', '573001234567')
                && $user['fn'] === hash('sha256', 'maria')
                && $user['ln'] === hash('sha256', 'perez')
                && $user['ct'] === hash('sha256', 'bogota')
                && $user['client_user_agent'] === 'Mozilla/5.0'
                && $user['client_ip_address'] === '181.1.2.3'
                && $user['fbp'] === 'fb.1.1700000000000.123456789'
                && ! isset($user['fbc']);
        });
        $this->assertNull(Cache::get(MetaConversionsClient::CONTEXT_CACHE_PREFIX.'TTV-ABC123'));
    }

    public function test_rejected_event_throws_so_the_outbox_retries(): void
    {
        $this->configure();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid token']], 400)]);

        $this->expectException(\RuntimeException::class);
        (new MetaConversionsClient())->sendPurchase($this->entry());
    }
}
