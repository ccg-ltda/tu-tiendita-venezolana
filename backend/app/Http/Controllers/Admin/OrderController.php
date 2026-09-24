<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminOrdersCache;
use App\Services\AppsScriptCheckoutClient;
use App\Services\AppsScriptCheckoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    public function index(Request $request, AdminOrdersCache $cache): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $page = max($request->integer('page', 1), 1);
        try { $entry = $cache->list($page, $perPage); } catch (\Throwable) { return $this->unavailable(); }
        if ($entry !== null) {
            Log::debug($entry['stale'] ? 'admin_orders_stale_served' : 'admin_orders_cache_hit', ['page' => $page, 'per_page' => $perPage]);
            return response()->json($entry['data']);
        }
        Log::notice('admin_orders_cache_miss', ['page' => $page, 'per_page' => $perPage]);
        return $this->unavailable();
    }

    public function show(string $order, AdminOrdersCache $cache, AppsScriptCheckoutClient $checkout): JsonResponse
    {
        if (! ctype_digit($order) || (int) $order < 1 || (int) $order > 2147483647) abort(404);
        $orderId = (int) $order;
        try { $entry = $cache->detail($orderId); } catch (\Throwable) { return $this->unavailable(); }
        if ($entry !== null) {
            Log::debug($entry['stale'] ? 'admin_order_detail_stale_served' : 'admin_order_detail_cache_hit', ['order_id' => $orderId]);
            return response()->json(['order' => $entry['data']]);
        }
        Log::notice('admin_order_detail_cache_miss', ['order_id' => $orderId]);
        try {
            $detail = $checkout->adminGetOrder($orderId);
            $cache->putDetail($orderId, $detail);
        } catch (AppsScriptCheckoutException $exception) {
            Log::warning('admin_order_detail_cache_refresh_failed', ['order_id' => $orderId, 'status' => $exception->status()]);

            return $this->unavailable();
        } catch (\Throwable) {
            Log::error('admin_order_detail_cache_refresh_failed', ['order_id' => $orderId]);

            return $this->unavailable();
        }

        return response()->json(['order' => $detail]);
    }

    public function updateStatus(Request $request, string $order, AdminOrdersCache $client, AppsScriptCheckoutClient $checkout): JsonResponse
    {
        if (! ctype_digit($order) || (int) $order < 1 || (int) $order > 2147483647) abort(404);
        $status = $request->input('status');
        if (! is_string($status) || ! in_array($status, self::STATUSES, true)) return response()->json(['message' => 'Estado de pedido inválido.'], 422);
        $orderId = (int) $order;
        try {
            $current = $checkout->adminGetOrder($orderId);
            $this->assertTransition($current['status'], $current['payment_status'], $status);
            $updated = $checkout->adminUpdateOrderStatus($orderId, $status);
        } catch (AppsScriptCheckoutException $exception) {
            return response()->json(['message' => $this->updateError($exception)], $exception->status());
        }

        $next = [...$current, 'status' => $updated['status'], 'updated_at' => $updated['updated_at']];
        $client->putDetail($orderId, $next);
        $this->updateCachedList($client, $orderId, $updated['status']);

        return response()->json(['order' => $next]);
    }

    private const STATUSES = ['PENDING', 'PROCESSING', 'READY', 'SHIPPED', 'DELIVERED', 'CANCELLED'];

    private function assertTransition(string $from, string $paymentStatus, string $to): void
    {
        if ($from === $to) return;
        if ($from === 'DELIVERED' || $from === 'CANCELLED') throw new AppsScriptCheckoutException(422, 'INVALID_STATUS_TRANSITION');
        if ($to === 'CANCELLED') return;
        $allowed = ['PENDING' => ['PROCESSING'], 'PROCESSING' => ['READY'], 'READY' => ['SHIPPED', 'DELIVERED'], 'SHIPPED' => ['DELIVERED']];
        if (! in_array($to, $allowed[$from] ?? [], true)) throw new AppsScriptCheckoutException(422, 'INVALID_STATUS_TRANSITION');
        if ($from === 'PENDING' && $to === 'PROCESSING' && $paymentStatus !== 'APPROVED') throw new AppsScriptCheckoutException(422, 'PAYMENT_NOT_APPROVED');
    }

    private function updateCachedList(AdminOrdersCache $cache, int $orderId, string $status): void
    {
        $entry = $cache->list(1, 25);
        if ($entry === null) return;
        $data = $entry['data'];
        foreach ($data['orders'] as &$order) if ($order['id'] === $orderId) $order['status'] = $status;
        unset($order);
        $cache->putList(1, 25, $data);
    }

    private function updateError(AppsScriptCheckoutException $exception): string
    {
        return match ($exception->remoteCode()) {
            'PAYMENT_NOT_APPROVED' => 'El pedido no puede procesarse hasta que el pago esté aprobado.',
            'INVALID_STATUS_TRANSITION' => 'La transición de estado no está permitida.',
            'ORDER_NOT_FOUND', 'NOT_FOUND' => 'Pedido no encontrado.',
            default => 'No fue posible actualizar el pedido.',
        };
    }

    private function unavailable(): JsonResponse
    {
        return response()->json(['message' => 'No fue posible cargar los pedidos.'], 503, ['Retry-After' => '15']);
    }
}
