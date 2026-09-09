<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $orders = Order::query()
            ->select(['id', 'reference', 'status', 'customer_name', 'total', 'created_at'])
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'orders' => $orders->getCollection()
                ->map(fn (Order $order): array => $this->listPayload($order))
                ->values(),
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'last_page' => $orders->lastPage(),
            ],
        ]);
    }

    public function show(int $order): JsonResponse
    {
        $order = Order::query()
            ->select([
                'id', 'reference', 'status', 'customer_name', 'customer_email',
                'customer_phone', 'customer_document', 'address', 'extra', 'city',
                'region', 'postal', 'total', 'created_at',
            ])
            ->with(['items' => fn ($query) => $query
                ->select(['id', 'order_id', 'product_id', 'product_name', 'unit_price', 'quantity'])
                ->orderBy('id')])
            ->findOrFail($order);

        return response()->json([
            'order' => [
                'id' => $order->id,
                'reference' => $order->reference,
                'status' => $order->status,
                'customer_name' => $order->customer_name,
                'customer_email' => $order->customer_email,
                'customer_phone' => $order->customer_phone,
                'customer_document' => $order->customer_document,
                'address' => $order->address,
                'extra' => $order->extra,
                'city' => $order->city,
                'region' => $order->region,
                'postal' => $order->postal,
                'total' => $order->total,
                'created_at' => $order->created_at,
                'items' => $order->items->map(fn ($item): array => [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'unit_price' => $item->unit_price,
                    'quantity' => $item->quantity,
                ])->values(),
            ],
        ]);
    }

    private function listPayload(Order $order): array
    {
        return [
            'id' => $order->id,
            'reference' => $order->reference,
            'status' => $order->status,
            'customer_name' => $order->customer_name,
            'total' => $order->total,
            'created_at' => $order->created_at,
        ];
    }
}
