<?php

namespace Tests\Unit\Services;

use App\Services\MySqlAdminOrderListService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MySqlAdminOrderListServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.mysql', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::purge('mysql');
        Schema::connection('mysql')->create('orders', function (Blueprint $table): void {
            $table->id(); $table->string('reference'); $table->string('status'); $table->string('payment_status'); $table->string('reservation_status'); $table->string('customer_name'); $table->unsignedInteger('total_cop'); $table->timestamps();
        });
        foreach ([
            [1, 'TTV-1', 'PENDING', 'PENDING', 'ACTIVE'],
            [2, 'TTV-2', 'PENDING', 'DECLINED', 'RELEASED'],
            [3, 'TTV-3', 'PENDING', 'APPROVED', 'CONSUMED'],
            [4, 'TTV-4', 'PROCESSING', 'PENDING', 'ACTIVE'],
        ] as [$id, $reference, $status, $payment, $reservation]) {
            DB::connection('mysql')->table('orders')->insert(['id' => $id, 'reference' => $reference, 'status' => $status, 'payment_status' => $payment, 'reservation_status' => $reservation, 'customer_name' => 'Cliente', 'total_cop' => 1000, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        }
    }

    public function test_flow_filters_apply_before_counting_and_pagination(): void
    {
        $service = new MySqlAdminOrderListService;

        $operational = $service->list(1, 1, 'operational');
        $this->assertSame(2, $operational['pagination']['total']);
        $this->assertSame('TTV-4', $operational['orders'][0]['reference']);

        $notCompleted = $service->list(1, 25, 'payment-not-completed');
        $this->assertSame(1, $notCompleted['pagination']['total']);
        $this->assertSame('TTV-2', $notCompleted['orders'][0]['reference']);
    }
}
