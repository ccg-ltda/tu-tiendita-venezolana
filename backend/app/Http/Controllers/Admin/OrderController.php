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

        // ENTREGA solo ve lo que va en camino o ya se entregó.
        $only = $this->role($request) === 'ENTREGA' ? ['SHIPPED', 'DELIVERED'] : null;

        return response()->json($orders->list($page, $perPage, $flowStatus, $only));
    }

    public function show(Request $request, string $order, CheckoutWriterGateway $checkout): JsonResponse
    {
        if (! ctype_digit($order) || (int) $order < 1 || (int) $order > 2147483647) abort(404);

        try {
            $found = $checkout->adminGetOrder((int) $order);
            if ($found === null || ! $this->canSee($this->role($request), $found['status'])) abort(404);

            return response()->json(['order' => $this->forRole($this->role($request), $found)]);
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

        $role = $this->role($request);
        if (! in_array($status, self::ROLE_TARGETS[$role] ?? [], true)) {
            return response()->json(['message' => 'Tu rol no puede cambiar el pedido a ese estado.'], 403);
        }

        $orderId = (int) $order;
        try {
            $current = $checkout->adminGetOrder($orderId);
            if ($current === null || ! $this->canSee($role, $current['status'])) abort(404);
            if ($role === 'ENTREGA' && $current['status'] !== 'SHIPPED') {
                return response()->json(['message' => 'Solo se puede marcar como entregado un pedido que ya va en camino.'], 403);
            }
            $this->assertTransition($current['status'], $current['payment_status'], $status, ($current['channel'] ?? 'WEB') === 'MANUAL');
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

        return response()->json(['order' => $this->forRole($role, $next)]);
    }

    private const STATUSES = ['PENDING', 'PROCESSING', 'READY', 'SHIPPED', 'DELIVERED'];

    /** Estados a los que cada rol puede llevar un pedido. */
    private const ROLE_TARGETS = [
        'ADMIN' => ['PENDING', 'PROCESSING', 'READY', 'SHIPPED', 'DELIVERED'],
        'DESPACHO' => ['PROCESSING', 'READY', 'SHIPPED', 'DELIVERED'],
        'ENTREGA' => ['DELIVERED'],
    ];

    private function role(Request $request): string
    {
        $admin = $request->attributes->get('admin_validated_account');

        return is_array($admin) && is_string($admin['role'] ?? null) ? $admin['role'] : '';
    }

    private function canSee(string $role, string $status): bool
    {
        return $role !== 'ENTREGA' || in_array($status, ['SHIPPED', 'DELIVERED'], true);
    }

    /** DESPACHO y ENTREGA ven nombre, teléfono, dirección y qué lleva; no correo, documento ni datos de pago. */
    private function forRole(string $role, array $order): array
    {
        if ($role === 'ADMIN') return $order;
        unset($order['customer_email'], $order['customer_document'], $order['payment'], $order['paid_at'], $order['payment_status'], $order['payment_flow_status']);

        return $order;
    }

    private function assertTransition(string $from, string $paymentStatus, string $to, bool $manual = false): void
    {
        if ($from === $to) return;
        if ($from === 'DELIVERED' || $from === 'CANCELLED') throw new CheckoutGatewayException(422, 'INVALID_STATUS_TRANSITION');
        $allowed = ['PENDING' => ['PROCESSING'], 'PROCESSING' => ['READY'], 'READY' => ['SHIPPED', 'DELIVERED'], 'SHIPPED' => ['DELIVERED']];
        if (! in_array($to, $allowed[$from] ?? [], true)) throw new CheckoutGatewayException(422, 'INVALID_STATUS_TRANSITION');
        if ($from === 'PENDING' && $to === 'PROCESSING' && $paymentStatus !== 'APPROVED' && ! $manual) throw new CheckoutGatewayException(422, 'PAYMENT_NOT_APPROVED');
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
