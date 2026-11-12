<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AuthenticatesAdmin;
use Tests\TestCase;

/** Dashboard, Clientes y módulo de WhatsApp del panel. */
final class AdminInsightsAndWhatsAppTest extends TestCase
{
    use AuthenticatesAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminDatabase();
        $s = Schema::connection('mysql');
        $s->create('orders', function (Blueprint $t): void {
            $t->id(); $t->string('reference'); $t->string('status'); $t->string('payment_status'); $t->string('reservation_status');
            $t->string('customer_name'); $t->string('customer_email'); $t->string('customer_phone'); $t->string('city');
            $t->unsignedInteger('total_cop'); $t->timestamps(3);
        });
        $s->create('order_items', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('order_id'); $t->unsignedBigInteger('product_id'); $t->string('product_name');
            $t->unsignedInteger('quantity'); $t->unsignedInteger('subtotal_cop');
        });
        $s->create('admin_audit_logs', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('admin_id')->nullable(); $t->string('username'); $t->string('action'); $t->string('resource_type');
            $t->string('resource_id'); $t->string('resource_label')->nullable(); $t->longText('before_json'); $t->longText('after_json'); $t->uuid('request_id'); $t->timestamp('created_at')->nullable();
        });
        $s->create('whatsapp_clicks', function (Blueprint $t): void { $t->id(); $t->string('topic'); $t->timestamp('created_at', 3)->useCurrent(); });
        $s->create('whatsapp_requests', function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->string('phone'); $t->string('topic'); $t->string('message')->nullable(); $t->string('status')->default('NEW');
            $t->unsignedBigInteger('assigned_admin_id')->nullable(); $t->unsignedBigInteger('created_by_admin_id')->nullable(); $t->timestamps(3);
        });

        // Hoy en Colombia: 2 pedidos pagados (Ana 2 veces) y 1 sin pagar; ayer en Colombia: 1 pagado (Luis).
        $this->order(1, 'ana@correo.com', 'Ana', 'Bogotá', 50000, 'APPROVED', '2026-11-10 15:00:00', [[7, 'Harina PAN', 2, 20000], [8, 'Toddy', 1, 30000]]);
        $this->order(2, 'ANA@correo.com', 'Ana Pérez', 'Bogotá', 30000, 'APPROVED', '2026-11-10 20:00:00', [[7, 'Harina PAN', 3, 30000]]);
        $this->order(3, 'x@correo.com', 'X', 'Cali', 99000, 'PENDING', '2026-11-10 21:00:00', [[9, 'Diablito', 1, 99000]]);
        // 02:00 UTC del 10 = 21:00 del 9 en Colombia
        $this->order(4, 'luis@correo.com', 'Luis', 'Medellín', 40000, 'APPROVED', '2026-11-10 02:00:00', [[8, 'Toddy', 1, 40000]]);
    }

    public function test_new_endpoints_require_an_administrator(): void
    {
        $this->getJson('/api/admin/dashboard')->assertUnauthorized();
        $this->getJson('/api/admin/customers')->assertUnauthorized();
        $this->getJson('/api/admin/whatsapp-requests')->assertUnauthorized();
    }

    public function test_dashboard_counts_only_paid_orders_grouped_in_colombia_time(): void
    {
        $r = $this->authenticatedAdmin()->getJson('/api/admin/dashboard?from=2026-11-10&to=2026-11-10')->assertOk();
        $r->assertJsonPath('current.sales', 80000)->assertJsonPath('current.orders', 2)->assertJsonPath('current.average_ticket', 40000);
        $r->assertJsonPath('previous.sales', 40000)->assertJsonPath('range.previous_from', '2026-11-09');
        $r->assertJsonPath('top_products.0.name', 'Harina PAN')->assertJsonPath('top_products.0.units', 5);
        $r->assertJsonPath('cities.0.city', 'Bogotá')->assertJsonPath('by_day.0.sales', 80000);
        $this->authenticatedAdmin()->getJson('/api/admin/dashboard?from=2026-11-12&to=2026-11-01')->assertStatus(422);
    }

    public function test_customers_are_grouped_by_email_with_spend_and_last_purchase(): void
    {
        $r = $this->authenticatedAdmin()->getJson('/api/admin/customers')->assertOk();
        $r->assertJsonCount(2, 'customers');
        $r->assertJsonPath('customers.0.email', 'ana@correo.com')->assertJsonPath('customers.0.orders', 2)
          ->assertJsonPath('customers.0.spent', 80000)->assertJsonPath('customers.0.name', 'Ana Pérez')
          ->assertJsonPath('customers.1.last_purchase', '2026-11-09');
    }

    public function test_whatsapp_requests_can_be_created_assigned_and_resolved(): void
    {
        $this->authenticatedAdmin()->postJson('/api/admin/whatsapp-requests', ['name' => 'Panadería La 14', 'phone' => '+57 310 000 0000', 'topic' => 'mayorista', 'message' => '20 harinas al mes'])->assertCreated();
        $this->authenticatedAdmin()->postJson('/api/admin/whatsapp-requests', ['name' => '', 'phone' => '1', 'topic' => 'otro'])->assertStatus(422);
        $id = (int) DB::connection('mysql')->table('whatsapp_requests')->value('id');

        $this->authenticatedAdmin()->patchJson("/api/admin/whatsapp-requests/{$id}", ['assigned_admin_id' => 1])->assertOk()->assertJsonPath('request.status', 'ATTENDING');
        $this->authenticatedAdmin()->patchJson("/api/admin/whatsapp-requests/{$id}", ['status' => 'RESOLVED'])->assertOk();
        $this->authenticatedAdmin()->patchJson("/api/admin/whatsapp-requests/{$id}", ['status' => 'RARO'])->assertStatus(422);

        $this->postJson('/api/whatsapp-clicks', ['topic' => 'soporte'])->assertCreated();
        $this->postJson('/api/whatsapp-clicks', ['topic' => 'hackeo'])->assertStatus(422);

        $list = $this->authenticatedAdmin()->getJson('/api/admin/whatsapp-requests')->assertOk();
        $list->assertJsonPath('requests.0.status', 'RESOLVED')->assertJsonPath('requests.0.assigned_name', 'Administrador de Prueba')
             ->assertJsonPath('requests.0.phone', '+573100000000')->assertJsonPath('clicks_30_days.soporte', 1);
        // Crear + asignar + resolver quedan en el historial del panel.
        $this->assertSame(3, DB::connection('mysql')->table('admin_audit_logs')->where('resource_type', 'WHATSAPP_REQUEST')->count());
    }

    /** @param list<array{0:int,1:string,2:int,3:int}> $items */
    private function order(int $id, string $email, string $name, string $city, int $total, string $payment, string $createdUtc, array $items): void
    {
        DB::connection('mysql')->table('orders')->insert(['id' => $id, 'reference' => "TTV-{$id}", 'status' => 'PENDING', 'payment_status' => $payment,
            'reservation_status' => 'ACTIVE', 'customer_name' => $name, 'customer_email' => $email, 'customer_phone' => '3000000000',
            'city' => $city, 'total_cop' => $total, 'created_at' => $createdUtc.'.000', 'updated_at' => $createdUtc.'.000']);
        foreach ($items as [$productId, $productName, $qty, $subtotal]) {
            DB::connection('mysql')->table('order_items')->insert(['order_id' => $id, 'product_id' => $productId, 'product_name' => $productName, 'quantity' => $qty, 'subtotal_cop' => $subtotal]);
        }
    }
}
