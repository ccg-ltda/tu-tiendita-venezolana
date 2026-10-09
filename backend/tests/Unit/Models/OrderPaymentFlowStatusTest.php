<?php

namespace Tests\Unit\Models;

use App\Models\Order;
use Tests\TestCase;

final class OrderPaymentFlowStatusTest extends TestCase
{
    public function test_payment_flow_status_is_derived_without_persisting_an_extra_state(): void
    {
        $this->assertSame('OPERATIONAL', $this->order('PENDING', 'APPROVED', 'CONSUMED')->paymentFlowStatus());
        $this->assertSame('AWAITING_PAYMENT', $this->order('PENDING', 'PENDING', 'ACTIVE')->paymentFlowStatus());
        $this->assertSame('PAYMENT_NOT_COMPLETED', $this->order('PENDING', 'PENDING', 'RELEASED')->paymentFlowStatus());
        $this->assertSame('PAYMENT_NOT_COMPLETED', $this->order('PENDING', 'DECLINED', 'RELEASED')->paymentFlowStatus());
    }

    private function order(string $status, string $paymentStatus, string $reservationStatus): Order
    {
        return new Order([
            'status' => $status,
            'payment_status' => $paymentStatus,
            'reservation_status' => $reservationStatus,
        ]);
    }
}
