<?php

namespace App\Http\Controllers\Public;

use App\Exceptions\CheckoutReservationPlanningException;
use App\Http\Controllers\Controller;
use App\Services\CheckoutCouponPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CheckoutCouponPreviewController extends Controller
{
    public function preview(Request $request, CheckoutCouponPreviewService $preview): JsonResponse
    {
        $payload = $request->all();
        $code = $payload['code'] ?? null;
        $items = $payload['items'] ?? null;
        if (array_diff(array_keys($payload), ['code', 'items']) !== [] || ! is_string($code) || ! is_array($items)) return $this->error('INVALID_REQUEST');
        foreach ($items as $item) if (! is_array($item) || array_diff(array_keys($item), ['id', 'qty']) !== []) return $this->error('INVALID_ITEMS');
        try {
            return response()->json($preview->preview($code, $items, now('UTC')));
        } catch (CheckoutReservationPlanningException $exception) {
            return $this->error($exception->checkoutCode(), 422, $exception->context());
        } catch (\Throwable) {
            return $this->error('UNAVAILABLE', 503);
        }
    }

    /** @param array<string,scalar|null> $context */
    private function error(string $code, int $status = 422, array $context = []): JsonResponse
    {
        $messages = [
            'COUPON_NOT_FOUND' => 'Este cupón no existe.', 'COUPON_INACTIVE' => 'Este cupón no está disponible.',
            'COUPON_SCHEDULED' => 'Este cupón todavía no está disponible.', 'COUPON_EXPIRED' => 'Este cupón ya venció.',
            'COUPON_EXHAUSTED' => 'Este cupón alcanzó su límite de usos.', 'COUPON_MINIMUM_NOT_MET' => 'Este cupón requiere una compra mínima.',
            'COUPON_NO_ELIGIBLE_ITEMS' => 'Este cupón no aplica a productos que ya tienen promoción.',
            'INVALID_COUPON' => 'Ingresa un código de descuento válido.', 'INVALID_ITEMS' => 'El pedido contiene productos inválidos.',
            'PRODUCT_NOT_FOUND', 'PRODUCT_INACTIVE' => 'Uno de los productos ya no está disponible.', 'INSUFFICIENT_STOCK' => 'Uno de los productos ya no tiene inventario suficiente.',
        ];
        $http = $code === 'UNAVAILABLE' || str_contains($code, '_CONTRACT') || $code === 'CATALOG_UNAVAILABLE' ? 503 : $status;
        return response()->json(array_filter(['ok' => false, 'code' => $code, 'error' => $messages[$code] ?? 'No fue posible validar el cupón en este momento.', 'minimum_order_cop' => $context['minimum_order_cop'] ?? null], static fn (mixed $value): bool => $value !== null), $http);
    }
}
