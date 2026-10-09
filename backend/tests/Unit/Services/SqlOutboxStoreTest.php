<?php

namespace Tests\Unit\Services;

use App\Services\OrderNotificationOutboxStore;
use App\Services\OrderNotificationDeliveryService;
use App\Services\WompiPaymentEventOutboxStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class SqlOutboxStoreTest extends TestCase
{
    private int $orderId;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'mysql',
            'database.connections.mysql' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('mysql');
        DB::reconnect('mysql');

        $schema = Schema::connection('mysql');
        $schema->create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 120)->unique();
            $table->string('status', 40);
            $table->string('payment_status', 40);
            $table->string('reservation_status', 40);
            $table->dateTime('reservation_expires_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('payment_last_event_at')->nullable();
            $table->char('idempotency_key_hash', 64)->unique();
            $table->char('checkout_payload_hash', 64);
            $table->char('release_id', 36)->nullable()->unique();
            $table->char('release_fingerprint', 64)->nullable();
            $table->dateTime('released_at')->nullable();
            $table->string('customer_name', 120);
            $table->string('customer_email', 254);
            $table->string('customer_phone', 50);
            $table->string('customer_document', 50);
            $table->string('address', 300);
            $table->string('extra', 300)->nullable();
            $table->string('city', 100);
            $table->string('region', 100);
            $table->string('postal', 30)->nullable();
            $table->unsignedInteger('total_cop');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });
        $schema->create('notification_outbox', function (Blueprint $table): void {
            $table->id();
            $table->string('notification_key', 180)->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnUpdate()->restrictOnDelete();
            $table->string('notification_type', 40);
            $table->string('recipient_kind', 20);
            $table->string('status', 20)->default('PENDING');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('available_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->longText('payload_json');
            $table->timestamps();
        });
        $schema->create('payment_event_outbox', function (Blueprint $table): void {
            $table->id();
            $table->string('wompi_transaction_id', 200)->unique();
            $table->string('reference', 120);
            $table->string('event_status', 40);
            $table->string('payment_method', 100);
            $table->unsignedBigInteger('amount_in_cents');
            $table->char('currency', 3)->default('COP');
            $table->dateTime('event_occurred_at');
            $table->string('status', 20)->default('PENDING');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('available_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
        });

        $this->orderId = DB::connection('mysql')->table('orders')->insertGetId([
            'reference' => 'TTV-TEST-1',
            'status' => 'PENDING',
            'payment_status' => 'PENDING',
            'reservation_status' => 'ACTIVE',
            'idempotency_key_hash' => str_repeat('a', 64),
            'checkout_payload_hash' => str_repeat('b', 64),
            'customer_name' => 'Cliente Test',
            'customer_email' => 'cliente@example.test',
            'customer_phone' => '+573000000000',
            'customer_document' => 'TEST-1',
            'address' => 'Calle 1',
            'city' => 'Bogota',
            'region' => 'Bogota',
            'total_cop' => 1000,
            'revision' => 1,
            'created_at' => '2026-10-08 12:00:00',
            'updated_at' => '2026-10-08 12:00:00',
        ]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('mysql');

        parent::tearDown();
    }

    public function test_notification_outbox_deduplicates_and_removes_successful_delivery(): void
    {
        $store = new OrderNotificationOutboxStore;
        $entry = ['notification_type' => 'PEDIDO_CREADO', 'recipient_kind' => 'customer', 'order_id' => $this->orderId, 'customer_email' => 'cliente@example.test'];
        $this->assertTrue($store->enqueue($entry)['created']);
        $this->assertTrue($store->enqueue($entry)['idempotency_replayed']);
        $this->assertCount(1, $store->all());
        $store->removeAfterSuccessfulDelivery('PEDIDO_CREADO:'.$this->orderId.':customer');
        $this->assertSame([], $store->all());
    }

    public function test_payment_event_outbox_deduplicates_and_tracks_retry_in_sql(): void
    {
        $store = new WompiPaymentEventOutboxStore;
        $event = ['id' => 'tx-1', 'reference' => 'TTV-1', 'status' => 'PENDING', 'payment_method' => 'CARD', 'amount_in_cents' => 10000, 'currency' => 'COP', 'event_occurred_at' => '2026-10-08T12:00:00.000Z'];
        $store->enqueue($event); $store->enqueue($event);
        $this->assertCount(1, $store->all());
        $store->markFailed($store->all()[0]);
        $this->assertSame([], $store->all());
        $this->assertSame(1, DB::connection('mysql')->table('payment_event_outbox')->value('attempts'));
        DB::connection('mysql')->table('payment_event_outbox')->update(['available_at' => now('UTC')]);
        $this->assertSame(1, $store->all()[0]['attempts']);
        $store->remove('tx-1');
        $this->assertSame([], $store->all());
    }

    public function test_successful_smtp_delivery_removes_the_sql_outbox_entry(): void
    {
        Mail::fake();
        $store = new OrderNotificationOutboxStore;
        $entry = ['notification_type' => 'PEDIDO_CREADO', 'recipient_kind' => 'customer', 'order_id' => $this->orderId,
            'reference' => 'TTV-1', 'customer_name' => 'Cliente', 'customer_email' => 'cliente@example.test',
            'customer_phone' => '+573000000000', 'address' => 'Calle 1', 'city' => 'Bogotá', 'total_cop' => 1000,
            'status' => 'PENDING', 'payment_status' => 'PENDING', 'reservation_status' => 'ACTIVE', 'items' => []];
        $store->enqueue($entry);
        $this->assertTrue((new OrderNotificationDeliveryService($store))->deliver($store->all()[0]));
        $this->assertSame([], $store->all());
    }

    public function test_failed_delivery_remains_pending_with_retry_metadata(): void
    {
        $store = new OrderNotificationOutboxStore;
        $entry = ['notification_type' => 'PEDIDO_CREADO', 'recipient_kind' => 'customer', 'order_id' => $this->orderId, 'customer_email' => 'cliente@example.test'];
        $store->enqueue($entry);
        $store->markFailed($store->all()[0], 'smtp unavailable');
        $this->assertSame([], $store->all());
        $pending = DB::connection('mysql')->table('notification_outbox')->first();
        $this->assertSame(1, $pending->attempts);
        $this->assertSame('smtp unavailable', $pending->last_error);
    }
}
