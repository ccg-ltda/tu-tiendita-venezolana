<?php

namespace Tests\Unit\Services;

use App\Services\OrderNotificationOutboxStore;
use Tests\TestCase;

final class OrderNotificationOutboxStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'order-notification-outbox-'.bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'*') ?: [] as $path) @unlink($path);
        parent::tearDown();
    }

    public function test_it_creates_customer_and_admin_entries_with_the_required_order_payload(): void
    {
        $store = new OrderNotificationOutboxStore($this->directory.'.json');
        $customer = $store->enqueue($this->payload('customer'));
        $admin = $store->enqueue($this->payload('admin'));

        $this->assertTrue($customer['created']);
        $this->assertTrue($admin['created']);
        $entries = $store->all();
        $this->assertCount(2, $entries);
        $this->assertSame('PEDIDO_CREADO:42:customer', $entries[0]['notification_key']);
        $this->assertSame('PEDIDO_CREADO', $entries[0]['notification_type']);
        $this->assertSame('customer', $entries[0]['recipient_kind']);
        $this->assertSame('PENDING', $entries[0]['status_internal']);
        $this->assertSame(0, $entries[0]['attempts']);
        $this->assertNull($entries[0]['last_error']);
        $this->assertSame([['product_id' => 8, 'product_name' => 'Harina', 'unit_price_cop' => 7000, 'quantity' => 2]], $entries[0]['items']);
        $this->assertSame('PEDIDO_CREADO:42:admin', $entries[1]['notification_key']);
    }

    public function test_same_notification_key_is_deduplicated_without_mutating_the_order_payload(): void
    {
        $store = new OrderNotificationOutboxStore($this->directory.'.json');
        $first = $store->enqueue($this->payload('customer'));
        $replay = $store->enqueue($this->payload('customer'));

        $this->assertTrue($first['created']);
        $this->assertFalse($replay['created']);
        $this->assertTrue($replay['idempotency_replayed']);
        $this->assertCount(1, $store->all());
        $this->assertSame($first['entry'], $replay['entry']);
    }

    public function test_operational_notification_keys_are_deduplicated_independently_without_regressing_order_created(): void
    {
        $store = new OrderNotificationOutboxStore($this->directory.'.json');
        foreach (['EN_PREPARACION', 'EN_CAMINO', 'ENTREGADO'] as $type) {
            $entry = $this->payload('customer');
            $entry['notification_type'] = $type;
            $entry['notification_key'] = $type.':42:customer';
            $this->assertTrue($store->enqueue($entry)['created']);
            $this->assertFalse($store->enqueue($entry)['created']);
        }
        $this->assertTrue($store->enqueue($this->payload('customer'))['created']);
        $this->assertCount(4, $store->all());
    }

    public function test_it_persists_delivery_failures_and_is_removed_only_by_the_successful_delivery_operation(): void
    {
        $store = new OrderNotificationOutboxStore($this->directory.'.json');
        $entry = $store->enqueue($this->payload('customer'))['entry'];

        $store->markFailed($entry, 'delivery transport unavailable');
        $failed = $store->all()[0];
        $this->assertSame(1, $failed['attempts']);
        $this->assertSame('delivery transport unavailable', $failed['last_error']);

        $store->removeAfterSuccessfulDelivery($failed['notification_key']);
        $this->assertSame([], $store->all());
    }

    /** @return array<string,mixed> */
    private function payload(string $recipient): array
    {
        return [
            'notification_key' => 'PEDIDO_CREADO:42:'.$recipient,
            'notification_type' => 'PEDIDO_CREADO',
            'recipient_kind' => $recipient,
            'order_id' => 42,
            'reference' => 'TTV-42',
            'customer_name' => 'Ana Perez',
            'customer_email' => 'ana@example.test',
            'customer_phone' => '+573001234567',
            'address' => 'Calle 1 # 2-3',
            'extra' => 'Apto 4',
            'city' => 'Bogota',
            'total_cop' => 14000,
            'status' => 'PENDING',
            'payment_status' => 'APPROVED',
            'reservation_status' => 'CONSUMED',
            'items' => [['product_id' => 8, 'product_name' => 'Harina', 'unit_price_cop' => 7000, 'quantity' => 2]],
        ];
    }
}
