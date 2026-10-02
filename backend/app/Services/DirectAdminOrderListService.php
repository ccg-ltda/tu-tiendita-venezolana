<?php

namespace App\Services;

use App\Repositories\CheckoutSheetsRepository;

/** Read-only administrative list projection backed by the direct Sheets repository. */
final class DirectAdminOrderListService
{
    public function __construct(private readonly CheckoutSheetsRepository $sheets) {}

    /** @return array{orders:list<array{id:int,reference:string,status:string,payment_status:string,customer_name:string,total:int,created_at:string}>,pagination:array{current_page:int,per_page:int,total:int,last_page:int}} */
    public function list(int $page, int $perPage): array
    {
        if ($page < 1 || $perPage < 1 || $perPage > 100) throw new \InvalidArgumentException('Invalid order page coordinates.');

        $orders = $this->sheets->readOrders();
        usort($orders, static fn (array $left, array $right): int => $right['order_id'] <=> $left['order_id']);
        $total = count($orders);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $slice = array_slice($orders, ($page - 1) * $perPage, $perPage);

        return [
            'orders' => array_map(static fn (array $order): array => [
                'id' => $order['order_id'],
                'reference' => $order['reference'],
                'status' => $order['status'],
                'payment_status' => $order['payment_status'],
                'customer_name' => $order['customer_name'],
                'total' => $order['total_cop'],
                'created_at' => $order['created_at'],
            ], $slice),
            'pagination' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => $lastPage],
        ];
    }
}
