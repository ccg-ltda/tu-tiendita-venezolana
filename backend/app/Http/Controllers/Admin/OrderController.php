<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminAuditService;
use App\Services\CheckoutGatewayException;
use App\Services\CheckoutWriterGateway;
use App\Services\MySqlAdminOrderListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request, MySqlAdminOrderListService $orders): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $page = max($request->integer('page', 1), 1);
        $flowStatus = $request->query('flow_status');
        if ($flowStatus !== null && (! is_string($flowStatus) || ! in_array($flowStatus, ['operational', 'payment-not-completed'], true))) {
            return response()->json(['message' => 'Filtro de pedidos invÃ¡lido.'], 422);
        }

        return response()->json($orders->list($page, $perPage, $flowStatus));
    }

    public function show(string $order, CheckoutWriterGateway $checkout): JsonResponse
    {
        if (! ctype_digit($order) || (int) $order < 1 || (int) $order > 2147483647) abort(404);

        try {
            return response()->json(['order' => $checkout->adminGetOrder((int) $order)]);
        } catch (CheckoutGatewayException $exception) {
            return $this->unavailable($exception);
        }
    }

    public function updateStatus(Request $request, string $order, CheckoutWriterGateway $checkout, AdminAuditService $audit): JsonResponse
    {
        if (! ctype_digit($order) || (int) $order < 1 || (int) $order > 2147483647) abort(404);
        $status = $request->input('status');
        if (! is_string($status) || ! in_array($status, self::STATUSES, true)) {
            return response()->json(['message' => 'Estado de pedido inválido.'], 422);
        }

        $orderId = (int) $order;
        try {
            $current = $checkout->adminGetOrder($orderId);
            $this->assertTransition($current['status'], $current['payment_status'], $status);
            $updated = $checkout->adminUpdateOrderStatus($orderId, $status);
        } catch (CheckoutGatewayException $exception) {
            return response()->json(['message' => $this->updateError($exception)], $exception->status());
        }

        $next = [...$current, 'status' => $updated['status'], 'updated_at' => $updated['updated_at']];
        if ($current['status'] !== $updated['status']) {
            $reference = $current['reference'];
            $audit->record($request, 'STATUS_CHANGE', 'ORDER', $reference, [
                'order_id' => $orderId, 'reference' => $reference, 'status' => $current['status'],
            ], [
                'order_id' => $orderId, 'reference' => $reference, 'status' => $updated['status'],
            ]);
        }

        return response()->json(['order' => $next]);
    }

    private const STATUSES = ['PENDING', 'PROCESSING', 'READY', 'SHIPPED', 'DELIVERED'];

    private function assertTransition(string $from, string $paymentStatus, string $to): void
    {
        if ($from === $to) return;
        if ($from === 'DELIVERED' || $from === 'CANCELLED') throw new CheckoutGatewayException(422, 'INVALID_STATUS_TRANSITION');
        $allowed = ['PENDING' => ['PROCESSING'], 'PROCESSING' => ['READY'], 'READY' => ['SHIPPED', 'DELIVERED'], 'SHIPPED' => ['DELIVERED']];
        if (! in_array($to, $allowed[$from] ?? [], true)) throw new CheckoutGatewayException(422, 'INVALID_STATUS_TRANSITION');
        if ($from === 'PENDING' && $to === 'PROCESSING' && $paymentStatus !== 'APPROVED') throw new CheckoutGatewayException(422, 'PAYMENT_NOT_APPROVED');
    }

    private function updateError(CheckoutGatewayException $exception): string
    {
        return match ($exception->remoteCode()) {
            'PAYMENT_NOT_APPROVED' => 'El pedido no puede procesarse hasta que el pago esté aprobado.',
            'INVALID_STATUS_TRANSITION' => 'La transición de estado no está permitida.',
            'ORDER_NOT_FOUND', 'NOT_FOUND' => 'Pedido no encontrado.',
            default => 'No fue posible actualizar el pedido.',
        };
    }

    private function unavailable(CheckoutGatewayException $exception): JsonResponse
    {
        if (in_array($exception->remoteCode(), ['ORDER_NOT_FOUND', 'NOT_FOUND'], true)) abort(404);
        return response()->json(['message' => 'No fue posible cargar los pedidos.'], $exception->status());
    }
}
