<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $customer = $request->input('customer');
        if (! is_array($customer) || ! $this->hasRequiredCustomerFields($customer)) {
            return response()->json(['error' => 'Completa todos los datos obligatorios.'], 400);
        }

        $requestedItems = $request->input('items');
        if (! is_array($requestedItems) || $requestedItems === []) {
            return response()->json(['error' => 'El pedido no tiene productos.'], 400);
        }

        $quantities = $this->normalizeItems($requestedItems);
        if ($quantities === null) {
            return response()->json(['error' => 'El pedido contiene productos inválidos.'], 400);
        }

        try {
            $order = DB::transaction(function () use ($customer, $quantities): Order {
                $productIds = array_keys($quantities);
                sort($productIds, SORT_NUMERIC);

                $products = Product::query()
                    ->whereIn('id', $productIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $items = [];
                $total = 0;

                foreach ($productIds as $productId) {
                    $product = $products->get($productId);
                    $quantity = $quantities[$productId];

                    if (! $product || ! $product->active) {
                        throw new OrderConflictException('Uno de los productos ya no está disponible.');
                    }

                    if ($product->inventory < $quantity) {
                        throw new OrderConflictException("Solo quedan {$product->inventory} unidades de {$product->name}.");
                    }

                    $items[] = compact('product', 'quantity');
                    $total += $product->price * $quantity;
                }

                $order = Order::query()->create([
                    'reference' => $this->newReference(),
                    'status' => 'PENDING',
                    'customer_name' => trim($customer['name']),
                    'customer_email' => trim($customer['email']),
                    'customer_phone' => trim($customer['phone']),
                    'customer_document' => trim($customer['document']),
                    'address' => trim($customer['address']),
                    'extra' => $this->optionalValue($customer['extra'] ?? null),
                    'city' => trim($customer['city']),
                    'region' => trim($customer['region']),
                    'postal' => $this->optionalValue($customer['postal'] ?? null),
                    'total' => $total,
                    'created_at' => now(),
                ]);

                foreach ($items as $item) {
                    $product = $item['product'];

                    OrderItem::query()->create([
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'unit_price' => $product->price,
                        'quantity' => $item['quantity'],
                    ]);

                    $product->decrement('inventory', $item['quantity']);
                }

                return $order;
            });
        } catch (OrderConflictException $exception) {
            return response()->json(['error' => $exception->getMessage()], 409);
        }

        return response()->json([
            'order' => [
                'id' => $order->id,
                'reference' => $order->reference,
                'status' => $order->status,
                'total' => $order->total,
            ],
        ], 201);
    }

    /** @param array<string, mixed> $customer */
    private function hasRequiredCustomerFields(array $customer): bool
    {
        foreach (['name', 'email', 'phone', 'document', 'address', 'city', 'region'] as $field) {
            if (! is_string($customer[$field] ?? null) || trim($customer[$field]) === '') {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, mixed> $requestedItems
     *  @return array<int, int>|null
     */
    private function normalizeItems(array $requestedItems): ?array
    {
        $quantities = [];

        foreach ($requestedItems as $item) {
            if (! is_array($item)) {
                return null;
            }

            $productId = $this->positiveInteger($item['id'] ?? null);
            $quantity = $this->positiveInteger($item['qty'] ?? null);
            if ($productId === null || $quantity === null) {
                return null;
            }

            $quantities[$productId] = ($quantities[$productId] ?? 0) + $quantity;
        }

        return $quantities;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value) && $value >= 1) {
            return $value;
        }

        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $integer === false ? null : $integer;
    }

    private function optionalValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function newReference(): string
    {
        $timestamp = strtoupper(base_convert((string) floor(microtime(true) * 1000), 10, 36));

        return "TTV-{$timestamp}-".strtoupper(bin2hex(random_bytes(2)));
    }
}

class OrderConflictException extends RuntimeException
{
}
