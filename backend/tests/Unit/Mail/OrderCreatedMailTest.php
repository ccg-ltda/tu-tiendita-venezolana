<?php

namespace Tests\Unit\Mail;

use App\Mail\OrderCreatedAdminMail;
use App\Mail\OrderCreatedCustomerMail;
use App\Mail\OrderDeliveredCustomerMail;
use App\Mail\OrderPreparingCustomerMail;
use App\Mail\OrderShippedCustomerMail;
use Tests\TestCase;

final class OrderCreatedMailTest extends TestCase
{
    public function test_customer_mailable_has_safe_order_content_and_subject(): void
    {
        $mail = new OrderCreatedCustomerMail($this->entry());
        $html = $mail->render();

        $this->assertSame('Pedido creado: TTV-88', $mail->envelope()->subject);
        $this->assertStringContainsString('TTV-88', $html);
        $this->assertStringContainsString('Harina', $html);
        $this->assertStringContainsString('14.000', $html);
        $this->assertStringNotContainsString('checkout-secret-key', $html);
        $this->assertStringNotContainsString('wompi-transaction-id', $html);
    }

    public function test_admin_mailable_has_safe_order_content_and_subject(): void
    {
        $mail = new OrderCreatedAdminMail($this->entry());
        $html = $mail->render();

        $this->assertSame('Nuevo pedido TTV-88 (#88)', $mail->envelope()->subject);
        $this->assertStringContainsString('TTV-88', $html);
        $this->assertStringContainsString('Harina', $html);
        $this->assertStringContainsString('14.000', $html);
        $this->assertStringContainsString('APPROVED', $html);
        $this->assertStringContainsString('PENDING', $html);
        $this->assertStringNotContainsString('checkout-secret-key', $html);
        $this->assertStringNotContainsString('wompi-transaction-id', $html);
    }

    public function test_operational_mailables_have_the_correct_subject_and_safe_visible_status(): void
    {
        foreach ([
            [OrderPreparingCustomerMail::class, 'PROCESSING', 'Tu pedido está en preparación: TTV-88', 'En preparación'],
            [OrderShippedCustomerMail::class, 'SHIPPED', 'Tu pedido va en camino: TTV-88', 'En camino'],
            [OrderDeliveredCustomerMail::class, 'DELIVERED', 'Tu pedido fue entregado: TTV-88', 'Entregado'],
        ] as [$class, $status, $subject, $visible]) {
            $entry = $this->entry(); $entry['status'] = $status; $mail = new $class($entry); $html = $mail->render();
            $this->assertSame($subject, $mail->envelope()->subject);$this->assertStringContainsString('TTV-88', $html);$this->assertStringContainsString($visible, $html);$this->assertStringContainsString('Harina', $html);$this->assertStringContainsString('14.000', $html);$this->assertStringNotContainsString('checkout-secret-key', $html);
        }
    }

    public function test_mailables_render_the_logo_inline_without_exposing_a_local_path(): void
    {
        foreach ([OrderCreatedCustomerMail::class, OrderCreatedAdminMail::class, OrderPreparingCustomerMail::class, OrderShippedCustomerMail::class, OrderDeliveredCustomerMail::class] as $class) {
            $html = (new $class($this->entry()))->render();

            $this->assertStringContainsString('data:image/png;base64,', $html);
            $this->assertStringNotContainsString('assets/logo.png', $html);
            $this->assertStringNotContainsString(str_replace('\\', '/', dirname(base_path())), str_replace('\\', '/', $html));
        }
    }

    public function test_brand_header_falls_back_to_the_brand_name_when_no_inline_logo_is_available(): void
    {
        $html = view('emails.orders.created-customer', ['entry' => $this->entry(), 'inlineLogoPath' => null])->render();

        $this->assertStringContainsString('Tu Tiendita Venezolana', $html);
        $this->assertStringNotContainsString('cid:', $html);
        $this->assertStringNotContainsString('assets/logo.png', $html);
    }

    /** @return array<string,mixed> */
    private function entry(): array
    {
        return [
            'order_id' => 88, 'reference' => 'TTV-88', 'customer_name' => 'Cliente', 'customer_email' => 'customer@example.test', 'customer_phone' => '+573001234567',
            'address' => 'Calle 1', 'extra' => null, 'city' => 'Bogota', 'total_cop' => 14000,
            'status' => 'PENDING', 'payment_status' => 'APPROVED',
            'items' => [['product_id' => 1, 'product_name' => 'Harina', 'unit_price_cop' => 7000, 'quantity' => 2]],
            'checkout_idempotency_key' => 'checkout-secret-key', 'wompi_transaction_id' => 'wompi-transaction-id',
        ];
    }
}
