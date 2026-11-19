<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\CheckoutReservationPlanningException;
use App\Http\Controllers\Controller;
use App\Models\OrderAttachment;
use App\Services\AdminAuditService;
use App\Services\SqlCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Pedido manual: solo ADMIN (la ruta ya exige el rol). */
class ManualOrderController extends Controller
{
    private const MAX_ATTACHMENT_BASE64 = 1_200_000; // ~900 KB; el panel reduce la foto antes de enviarla
    private const ATTACHMENT_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public function store(Request $request, SqlCheckoutService $checkout, AdminAuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'customer.name' => ['required', 'string', 'min:2', 'max:120'],
            'customer.phone' => ['required', 'string', 'max:30'],
            'customer.email' => ['nullable', 'email', 'max:160'],
            'customer.city' => ['required', 'string', 'min:2', 'max:100'],
            'customer.region' => ['nullable', 'string', 'max:100'],
            'customer.address' => ['required', 'string', 'min:4', 'max:300'],
            'customer.extra' => ['nullable', 'string', 'max:300'],
            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'payment_method' => ['required', Rule::in(SqlCheckoutService::MANUAL_PAYMENT_METHODS)],
            'paid' => ['nullable', 'boolean'],
            'shipping_cop' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:1000'],
            'attachment.name' => ['nullable', 'string', 'max:160'],
            'attachment.mime' => ['nullable', Rule::in(self::ATTACHMENT_MIMES)],
            'attachment.data' => ['nullable', 'string', 'max:'.self::MAX_ATTACHMENT_BASE64],
        ], [], ['customer.name' => 'nombre', 'customer.phone' => 'teléfono', 'customer.city' => 'ciudad', 'customer.address' => 'dirección']);

        $phone = preg_replace('/\D/', '', $data['customer']['phone']) ?? '';
        if (strlen($phone) < 7 || strlen($phone) > 15) {
            return response()->json(['message' => 'Revisa el teléfono del cliente.'], 422);
        }
        $attachment = null;
        if (! empty($data['attachment']['data'])) {
            if (empty($data['attachment']['mime']) || base64_decode($data['attachment']['data'], true) === false) {
                return response()->json(['message' => 'El soporte de pago no es válido.'], 422);
            }
            $attachment = ['name' => $data['attachment']['name'] ?? 'soporte', 'mime' => $data['attachment']['mime'], 'data' => $data['attachment']['data']];
        }
        $adminId = (int) $request->session()->get('admin_id');

        try {
            $created = $checkout->createManualOrder(
                [
                    'name' => trim($data['customer']['name']), 'phone' => $phone, 'email' => isset($data['customer']['email']) ? strtolower(trim($data['customer']['email'])) : null,
                    'city' => trim($data['customer']['city']), 'region' => trim($data['customer']['region'] ?? '') ?: trim($data['customer']['city']),
                    'address' => trim($data['customer']['address']), 'extra' => isset($data['customer']['extra']) ? trim($data['customer']['extra']) : null,
                ],
                array_map(static fn (array $i): array => ['product_id' => (int) $i['product_id'], 'quantity' => (int) $i['quantity']], $data['items']),
                $data['payment_method'], (bool) ($data['paid'] ?? false), isset($data['shipping_cop']) ? (int) $data['shipping_cop'] : null,
                isset($data['note']) ? (trim($data['note']) ?: null) : null, $attachment, $adminId,
            );
        } catch (CheckoutReservationPlanningException $exception) {
            return response()->json(['message' => match ($exception->checkoutCode()) {
                'PRODUCT_NOT_FOUND' => 'Uno de los productos ya no existe.',
                'PRODUCT_INACTIVE' => 'Uno de los productos está desactivado.',
                'INSUFFICIENT_STOCK' => 'No hay inventario suficiente de uno de los productos.',
                default => 'No fue posible crear el pedido.',
            }], 422);
        }

        $audit->record($request, 'CREATE', 'ORDER', $created['reference'], [], [
            'order_id' => $created['order_id'], 'reference' => $created['reference'], 'channel' => 'MANUAL',
            'payment_method' => $data['payment_method'], 'total' => $created['total_cop'],
        ]);

        return response()->json(['order' => $created], 201);
    }

    public function attachment(string $order, string $attachment): Response
    {
        if (! ctype_digit($order) || ! ctype_digit($attachment)) abort(404);
        $file = OrderAttachment::query()->where('order_id', (int) $order)->whereKey((int) $attachment)->first()?->makeVisible('data_base64');
        if ($file === null) abort(404);

        return response(base64_decode($file->data_base64), 200, [
            'Content-Type' => $file->mime, 'Cache-Control' => 'private, max-age=600', 'X-Robots-Tag' => 'noindex',
            'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline',
        ]);
    }
}
