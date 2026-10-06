<?php

namespace Tests\Unit\Services;

use App\Mail\OrderCreatedAdminMail;
use App\Mail\OrderCreatedCustomerMail;
use App\Mail\OrderDeliveredCustomerMail;
use App\Mail\OrderPreparingCustomerMail;
use App\Mail\OrderShippedCustomerMail;
use App\Services\OrderNotificationDeliveryService;
use App\Services\OrderNotificationOutboxStore;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

final class OrderNotificationDeliveryServiceTest extends TestCase
{
    private string $path;
    private OrderNotificationOutboxStore $outbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'order-notification-delivery-'.bin2hex(random_bytes(5)).'.json';
        $this->outbox = new OrderNotificationOutboxStore($this->path);
        Mail::fake();
        config(['services.order_notifications.admin_email' => 'admin@example.test']);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_customer_delivery_uses_the_customer_recipient_and_mailable(): void
    {
        $entry = $this->outbox->enqueue($this->payload('customer'))['entry'];
        $this->outbox->enqueue($this->payload('admin'));

        $this->assertTrue($this->service()->deliver($entry));
        Mail::assertSent(OrderCreatedCustomerMail::class, fn (OrderCreatedCustomerMail $mail): bool => $mail->hasTo('customer@example.test'));
        $remaining = $this->outbox->all();
        $this->assertCount(1, $remaining);
        $this->assertSame('admin', $remaining[0]['recipient_kind']);
    }

    public function test_admin_delivery_uses_one_configured_recipient_and_mailable(): void
    {
        $entry = $this->outbox->enqueue($this->payload('admin'))['entry'];

        $this->assertTrue($this->service()->deliver($entry));
        Mail::assertSent(OrderCreatedAdminMail::class, fn (OrderCreatedAdminMail $mail): bool => $mail->hasTo('admin@example.test'));
        $this->assertSame([], $this->outbox->all());
    }

    public function test_admin_delivery_sends_one_message_to_two_configured_recipients(): void
    {
        config(['services.order_notifications.admin_email' => 'admin1@example.test,admin2@example.test']);
        $entry = $this->outbox->enqueue($this->payload('admin'))['entry'];

        $this->assertTrue($this->service()->deliver($entry));
        Mail::assertSent(OrderCreatedAdminMail::class, function (OrderCreatedAdminMail $mail): bool {
            return $this->toAddresses($mail) === ['admin1@example.test', 'admin2@example.test'];
        });
        Mail::assertSent(OrderCreatedAdminMail::class, 1);
        $this->assertSame([], $this->outbox->all());
    }

    public function test_admin_delivery_normalizes_spaces_duplicates_and_empty_values(): void
    {
        config(['services.order_notifications.admin_email' => ' admin1@example.test, , admin2@example.test ,admin1@example.test,, ']);
        $entry = $this->outbox->enqueue($this->payload('admin'))['entry'];

        $this->assertTrue($this->service()->deliver($entry));
        Mail::assertSent(OrderCreatedAdminMail::class, function (OrderCreatedAdminMail $mail): bool {
            return $this->toAddresses($mail) === ['admin1@example.test', 'admin2@example.test'];
        });
        Mail::assertSent(OrderCreatedAdminMail::class, 1);
        $this->assertSame([], $this->outbox->all());
    }

    public function test_invalid_customer_recipient_is_recorded_and_kept_pending(): void
    {
        $entry = $this->outbox->enqueue($this->payload('customer'))['entry'];
        $entry['customer_email'] = '';

        $this->assertFalse($this->service()->deliver($entry));
        $stored = $this->outbox->all()[0];
        $this->assertSame(1, $stored['attempts']);
        $this->assertSame('Invalid customer notification email.', $stored['last_error']);
    }

    public function test_missing_admin_recipient_is_recorded_and_kept_pending(): void
    {
        config(['services.order_notifications.admin_email' => null]);
        $entry = $this->outbox->enqueue($this->payload('admin'))['entry'];

        $this->assertFalse($this->service()->deliver($entry));
        $stored = $this->outbox->all()[0];
        $this->assertSame(1, $stored['attempts']);
        $this->assertSame('Order notification admin email is unavailable.', $stored['last_error']);
    }

    public function test_admin_delivery_with_no_valid_configured_recipients_is_recorded_and_kept_pending(): void
    {
        config(['services.order_notifications.admin_email' => ' ,not-an-email,also-not-an-email, ']);
        $entry = $this->outbox->enqueue($this->payload('admin'))['entry'];

        $this->assertFalse($this->service()->deliver($entry));
        $stored = $this->outbox->all()[0];
        $this->assertSame(1, $stored['attempts']);
        $this->assertSame('Order notification admin email is unavailable.', $stored['last_error']);
        Mail::assertNothingSent();
    }

    public function test_mail_exception_is_recorded_and_the_entry_remains_for_retry(): void
    {
        $entry = $this->outbox->enqueue($this->payload('customer'))['entry'];
        $mailer = Mockery::mock();
        $mailer->shouldReceive('to')->once()->with('customer@example.test')->andReturnSelf();
        $mailer->shouldReceive('send')->once()->with(Mockery::type(OrderCreatedCustomerMail::class))->andThrow(new \RuntimeException('SMTP unavailable'));
        Mail::swap($mailer);

        $this->assertFalse($this->service()->deliver($entry));
        $stored = $this->outbox->all()[0];
        $this->assertSame(1, $stored['attempts']);
        $this->assertSame('SMTP unavailable', $stored['last_error']);
    }

    public function test_admin_batch_failure_keeps_one_outbox_entry_for_retry(): void
    {
        config(['services.order_notifications.admin_email' => 'admin1@example.test,admin2@example.test']);
        $entry = $this->outbox->enqueue($this->payload('admin'))['entry'];
        $mailer = Mockery::mock();
        $mailer->shouldReceive('to')->once()->with(['admin1@example.test', 'admin2@example.test'])->andReturnSelf();
        $mailer->shouldReceive('send')->once()->with(Mockery::type(OrderCreatedAdminMail::class))->andThrow(new \RuntimeException('SMTP unavailable'));
        Mail::swap($mailer);

        $this->assertFalse($this->service()->deliver($entry));
        $stored = $this->outbox->all()[0];
        $this->assertSame('PEDIDO_CREADO:55:admin', $stored['notification_key']);
        $this->assertSame(1, $stored['attempts']);
        $this->assertSame('SMTP unavailable', $stored['last_error']);
    }

    public function test_operational_types_select_their_customer_mailable_and_remove_only_the_delivered_entry(): void
    {
        foreach ([
            ['EN_PREPARACION', OrderPreparingCustomerMail::class],
            ['EN_CAMINO', OrderShippedCustomerMail::class],
            ['ENTREGADO', OrderDeliveredCustomerMail::class],
        ] as [$type, $mailable]) {
            $entry = $this->payload('customer', $type);
            $this->outbox->enqueue($entry);
            $this->assertTrue($this->service()->deliver($this->outbox->all()[0]));
            Mail::assertSent($mailable, fn ($mail): bool => $mail->hasTo('customer@example.test'));
            $this->assertSame([], $this->outbox->all());
        }
    }

    private function service(): OrderNotificationDeliveryService
    {
        return new OrderNotificationDeliveryService($this->outbox);
    }

    /** @return list<string> */
    private function toAddresses(OrderCreatedAdminMail $mail): array
    {
        return array_map(static fn (array $recipient): string => $recipient['address'], $mail->to);
    }

    /** @return array<string,mixed> */
    private function payload(string $recipient, string $type = 'PEDIDO_CREADO'): array
    {
        return [
            'notification_key' => $type.':55:'.$recipient, 'notification_type' => $type, 'recipient_kind' => $recipient,
            'order_id' => 55, 'reference' => 'TTV-55', 'customer_name' => 'Cliente', 'customer_email' => 'customer@example.test', 'customer_phone' => '+573001234567',
            'address' => 'Calle 1', 'extra' => null, 'city' => 'Bogota', 'total_cop' => 12000,
            'status' => 'PENDING', 'payment_status' => 'APPROVED', 'reservation_status' => 'CONSUMED',
            'items' => [['product_id' => 1, 'product_name' => 'Harina', 'unit_price_cop' => 6000, 'quantity' => 2]],
        ];
    }
}
