<?php

namespace Tests\Feature\Commands;

use App\Mail\OrderCreatedCustomerMail;
use App\Services\OrderNotificationOutboxStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class SendPendingOrderNotificationsTest extends TestCase
{
    private string $path;
    private OrderNotificationOutboxStore $outbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'send-order-notifications-'.bin2hex(random_bytes(5)).'.json';
        $this->outbox = new OrderNotificationOutboxStore($this->path);
        $this->app->instance(OrderNotificationOutboxStore::class, $this->outbox);
        Mail::fake();
        config(['services.order_notifications.admin_email' => 'admin@example.test']);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_command_processes_all_pending_entries_and_second_run_does_not_resend_delivered_entries(): void
    {
        $this->outbox->enqueue($this->payload('customer'));
        $this->outbox->enqueue($this->payload('admin'));
        $this->outbox->enqueue($this->payload('customer', 'EN_PREPARACION'));
        $this->outbox->enqueue($this->payload('customer', 'EN_CAMINO'));
        $this->outbox->enqueue($this->payload('customer', 'ENTREGADO'));

        $this->artisan('orders:send-pending-notifications')->expectsOutput('sent=5 failed=0 pending=0')->assertExitCode(0);
        $this->assertSame([], $this->outbox->all());
        $this->artisan('orders:send-pending-notifications')->expectsOutput('sent=0 failed=0 pending=0')->assertExitCode(0);
        Mail::assertSent(OrderCreatedCustomerMail::class, 1);
    }

    public function test_failed_entry_does_not_stop_the_next_entry_and_is_reported_as_pending(): void
    {
        config(['services.order_notifications.admin_email' => null]);
        $this->outbox->enqueue($this->payload('admin'));
        $this->outbox->enqueue($this->payload('customer'));

        $this->artisan('orders:send-pending-notifications')->expectsOutput('sent=1 failed=1 pending=1')->assertExitCode(1);
        $entries = $this->outbox->all();
        $this->assertCount(1, $entries);
        $this->assertSame('admin', $entries[0]['recipient_kind']);
        $this->assertSame(1, $entries[0]['attempts']);
        Mail::assertSent(OrderCreatedCustomerMail::class, 1);
    }

    public function test_scheduler_registers_delivery_each_minute_without_removing_existing_tasks(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'orders:send-pending-notifications'));

        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertNotNull(collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'wompi:sync-pending-payment-events')));
    }

    /** @return array<string,mixed> */
    private function payload(string $recipient, string $type = 'PEDIDO_CREADO'): array
    {
        return [
            'notification_key' => $type.':77:'.$recipient, 'notification_type' => $type, 'recipient_kind' => $recipient,
            'order_id' => 77, 'reference' => 'TTV-77', 'customer_name' => 'Cliente', 'customer_email' => 'customer@example.test', 'customer_phone' => '+573001234567',
            'address' => 'Calle 1', 'extra' => null, 'city' => 'Bogota', 'total_cop' => 12000,
            'status' => 'PENDING', 'payment_status' => 'APPROVED', 'reservation_status' => 'CONSUMED',
            'items' => [['product_id' => 1, 'product_name' => 'Harina', 'unit_price_cop' => 6000, 'quantity' => 2]],
        ];
    }
}
