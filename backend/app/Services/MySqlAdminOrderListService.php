<?php

namespace App\Services;

use App\Models\Order;

final class MySqlAdminOrderListService
{
    /** @return array{orders:list<array<string,mixed>>,pagination:array<string,int>} */
    public function list(int $page, int $perPage, ?string $flowStatus = null): array
    {
        if ($page < 1 || $perPage < 1 || $perPage > 100) {
            throw new \InvalidArgumentException('Invalid order page coordinates.');
        }

        $query = Order::query();
        if ($flowStatus === 'operational') {
            $query->where(function ($orders): void {
                $orders->where('payment_status', 'APPROVED')
                    ->orWhereIn('status', ['PROCESSING', 'READY', 'SHIPPED', 'DELIVERED']);
            });
        } elseif ($flowStatus === 'payment-not-completed') {
            $query->where('status', 'PENDING')
                ->where('payment_status', '!=', 'APPROVED')
                ->where('reservation_status', 'RELEASED');
        }

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $orders = $query->orderByDesc('id')->forPage($page, $perPage)->get();

        return [
            'orders' => $orders->map(static fn (Order $order): array => [
                'id' => $order->id,
                'reference' => $order->reference,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'reservation_status' => $order->reservation_status,
                'payment_flow_status' => $order->paymentFlowStatus(),
                'customer_name' => $order->customer_name,
                'total' => $order->total_cop,
                'created_at' => $order->created_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
            ])->all(),
            'pagination' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => $lastPage],
        ];
    }
}
