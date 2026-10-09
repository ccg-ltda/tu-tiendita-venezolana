<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\CheckoutWriterGateway;
use App\Services\CheckoutGatewayException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class WompiPaymentController extends Controller
{
    private const STATUS_TOKEN_HOURS = 24;

    private const AMBIGUOUS_PREPARE_TTL_MINUTES = 15;

    public function prepare(Request $request, CheckoutWriterGateway $client): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        $keyHash = is_string($idempotencyKey) ? substr(hash('sha256', $idempotencyKey), 0, 12) : null;
        Log::info('wompi_prepare_diagnostic_started', ['idempotency_key_hash' => $keyHash]);
        if (! is_string($idempotencyKey) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $idempotencyKey) !== 1) {
            Log::info('wompi_prepare_diagnostic_finished', ['idempotency_key_hash' => $keyHash, 'http_status' => 400, 'reason' => 'invalid_idempotency_key']);
            return response()->json(['error' => 'Idempotency-Key inválida.'], 400);
        }

        try {
            $checkout = $this->normalizeCheckout($request->input('customer'), $request->input('items'), $request->input('coupon_code'), $idempotencyKey);
        } catch (WompiPrepareValidationException $exception) {
            Log::info('wompi_prepare_diagnostic_validation_failed', ['idempotency_key_hash' => $keyHash, 'exception_class' => $exception::class, 'http_status' => 400]);
            return response()->json(['error' => $exception->getMessage()], 400);
        }

        $stateKey = $this->prepareStateKey($idempotencyKey);
        $state = Cache::get($stateKey);
        $statePayloadHash = is_array($state) && is_string($state['payload_hash'] ?? null)
            ? $state['payload_hash']
            : null;
        $checkout['recover_after_ambiguous_prepare'] = is_array($state)
            && ($state['state'] ?? null) === 'prepare_ambiguous'
            && $statePayloadHash !== null
            && hash_equals($checkout['payload_hash'], $statePayloadHash);
        Log::info('wompi_prepare_diagnostic_state', ['idempotency_key_hash' => $keyHash, 'prepare_state' => is_array($state) ? ($state['state'] ?? 'invalid') : 'absent', 'recover_after_ambiguous_prepare' => $checkout['recover_after_ambiguous_prepare']]);

        $wompi = $this->wompiConfiguration();
        if ($wompi === null) {
            Log::warning('wompi_prepare_diagnostic_finished', ['idempotency_key_hash' => $keyHash, 'http_status' => 503, 'reason' => 'wompi_configuration_unavailable']);
            return response()->json(['error' => 'No es posible preparar el pago en este momento.'], 503);
        }

        try {
            Log::info('wompi_prepare_diagnostic_sql_call', ['idempotency_key_hash' => $keyHash, 'action' => 'prepare_checkout']);
            $prepared = $client->prepareCheckout($checkout);
            Cache::put($stateKey, ['state' => 'prepare_successful', 'payload_hash' => $checkout['payload_hash']], now()->addMinutes(self::AMBIGUOUS_PREPARE_TTL_MINUTES));

            $status = $prepared['idempotency_replayed'] ? 200 : 201;
            Log::info('wompi_prepare_diagnostic_finished', ['idempotency_key_hash' => $keyHash, 'http_status' => $status, 'idempotency_replayed' => $prepared['idempotency_replayed'], 'prepare_ambiguous_marked' => false]);
            return $this->preparedResponse($prepared, $wompi, $status);
        } catch (CheckoutGatewayException $exception) {
            if ($checkout['recover_after_ambiguous_prepare'] && $exception->remoteCode() === 'RESERVATION_EXPIRED') {
                Cache::forget($stateKey);
                Log::warning('wompi_prepare_diagnostic_expired_ambiguous_recovery', ['idempotency_key_hash' => $keyHash, 'http_status' => 409, 'remote_code' => $exception->remoteCode(), 'prepare_ambiguous_cleared' => true, 'recover_after_ambiguous_prepare' => true]);

                return response()->json([
                    'error' => 'La reserva anterior venció. Vuelve a continuar al pago para generar una nueva.',
                    'code' => 'RESERVATION_EXPIRED',
                ], 409);
            }
            $mayMarkAmbiguous = ! is_array($state)
                || (($state['state'] ?? null) === 'prepare_ambiguous'
                    && $statePayloadHash !== null
                    && hash_equals($checkout['payload_hash'], $statePayloadHash));
            $markedAmbiguous = $exception->ambiguousPrepareFailure() && $mayMarkAmbiguous;
            if ($markedAmbiguous) {
                Cache::put($stateKey, ['state' => 'prepare_ambiguous', 'payload_hash' => $checkout['payload_hash']], now()->addMinutes(self::AMBIGUOUS_PREPARE_TTL_MINUTES));
            }
            Log::warning('wompi_prepare_diagnostic_finished', ['idempotency_key_hash' => $keyHash, 'http_status' => $exception->status(), 'exception_class' => $exception::class, 'remote_code' => $exception->remoteCode(), 'timeout' => $exception->status() === 504, 'curl_errno' => null, 'prepare_ambiguous_marked' => $markedAmbiguous, 'recover_after_ambiguous_prepare' => $checkout['recover_after_ambiguous_prepare']]);

            $body = ['error' => $this->checkoutError($exception)];
            // The client needs to invalidate a stale read-only coupon preview;
            // keep technical codes private for every other checkout failure.
            if (str_starts_with((string) $exception->remoteCode(), 'COUPON_')) $body['code'] = $exception->remoteCode();
            return response()->json($body, $exception->status());
        } catch (\Throwable $exception) {
            Log::error('wompi_prepare_diagnostic_finished', ['idempotency_key_hash' => $keyHash, 'http_status' => 503, 'exception_class' => $exception::class, 'prepare_ambiguous_marked' => false, 'recover_after_ambiguous_prepare' => $checkout['recover_after_ambiguous_prepare']]);
            return response()->json(['error' => 'No es posible preparar el pago en este momento.'], 503);
        }
    }

    private function prepareStateKey(string $idempotencyKey): string
    {
        return 'wompi:prepare-state:'.hash('sha256', $idempotencyKey);
    }

    /** @return array{customer: array<string, string|null>, items: list<array{product_id: int, quantity: int}>, idempotency_key: string, payload_hash: string} */
    private function normalizeCheckout(mixed $rawCustomer, mixed $rawItems, mixed $rawCouponCode, string $idempotencyKey): array
    {
        if (! is_array($rawCustomer)) {
            throw new WompiPrepareValidationException('Completa todos los datos obligatorios.');
        }
        $customer = [
            'name' => $this->requiredText($rawCustomer['name'] ?? null, 2, 120), 'email' => $this->email($rawCustomer['email'] ?? null),
            'phone' => $this->phone($rawCustomer['phone'] ?? null), 'document' => $this->document($rawCustomer['document'] ?? null),
            'address' => $this->requiredText($rawCustomer['address'] ?? null, 5, 300), 'extra' => $this->nullableText($rawCustomer['extra'] ?? null, 300),
            'city' => $this->requiredText($rawCustomer['city'] ?? null, 2, 100), 'region' => $this->requiredText($rawCustomer['region'] ?? null, 2, 100), 'postal' => $this->postal($rawCustomer['postal'] ?? null),
        ];
        if (! is_array($rawItems) || $rawItems === []) {
            throw new WompiPrepareValidationException('El pedido no tiene productos.');
        }
        $quantities = [];
        foreach ($rawItems as $item) {
            if (! is_array($item) || ($id = $this->positiveInteger($item['id'] ?? null, 2147483647)) === null || ($quantity = $this->positiveInteger($item['qty'] ?? null, 999)) === null) {
                throw new WompiPrepareValidationException('El pedido contiene productos inválidos.');
            }
            $quantities[$id] = ($quantities[$id] ?? 0) + $quantity;
            if ($quantities[$id] > 999) {
                throw new WompiPrepareValidationException('El pedido contiene productos inválidos.');
            }
        }
        ksort($quantities, SORT_NUMERIC);
        if (count($quantities) > 50) {
            throw new WompiPrepareValidationException('El pedido contiene productos inválidos.');
        }
        $items = array_map(static fn (int $id, int $quantity): array => ['product_id' => $id, 'quantity' => $quantity], array_keys($quantities), array_values($quantities));
        if ($rawCouponCode !== null && ! is_string($rawCouponCode)) {
            throw new WompiPrepareValidationException('El cupón no es válido.');
        }
        $couponCode = $rawCouponCode === null ? null : \App\Coupons\CouponNormalizer::normalizeCode($rawCouponCode);
        if ($rawCouponCode !== null && $couponCode === null) {
            throw new WompiPrepareValidationException('El cupón no es válido.');
        }
        $canonical = json_encode(['customer' => $customer, 'items' => $items, 'coupon_code' => $couponCode], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return ['customer' => $customer, 'items' => $items, 'coupon_code' => $couponCode, 'idempotency_key' => $idempotencyKey, 'payload_hash' => hash('sha256', $canonical)];
    }

    private function normalizedText(mixed $value): string
    {
        if (! is_string($value) || ! class_exists(\Normalizer::class)) {
            throw new WompiPrepareValidationException('Completa todos los datos obligatorios.');
        }
        $text = \Normalizer::normalize($value, \Normalizer::FORM_C);
        if (! is_string($text) || preg_match('/[\x{0}-\x{8}\x{E}-\x{1F}\x{7F}]/u', $text) === 1) {
            throw new WompiPrepareValidationException('Completa todos los datos obligatorios.');
        }

        return trim((string) preg_replace('/[\x{9}-\x{D}\x{20}\x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+/u', ' ', $text));
    }

    private function requiredText(mixed $value, int $min, int $max): string
    {
        $text = $this->normalizedText($value);
        if (mb_strlen($text) < $min || mb_strlen($text) > $max) {
            throw new WompiPrepareValidationException('Completa todos los datos obligatorios.');
        }

return $text;
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        } $text = $this->normalizedText($value);
        if ($text === '') {
            return null;
        } if (mb_strlen($text) > $max) {
            throw new WompiPrepareValidationException('Completa todos los datos obligatorios.');
        }

return $text;
    }

    private function email(mixed $value): string
    {
        $email = $this->normalizedText($value);
        if (preg_match('/[^\x00-\x7F]/', $email) === 1 || strlen($email) < 3 || strlen($email) > 254 || preg_match("/^[A-Z0-9.!#$%&'*+\/=?^_`{|}~-]+@[A-Z0-9](?:[A-Z0-9-]{0,61}[A-Z0-9])?(?:\.[A-Z0-9](?:[A-Z0-9-]{0,61}[A-Z0-9])?)+$/iD", $email) !== 1) {
            throw new WompiPrepareValidationException('Completa todos los datos obligatorios.');
        }

return strtolower($email);
    }

    private function phone(mixed $value): string
    {
        if (! is_string($value)) {
            throw new WompiPrepareValidationException('Completa todos los datos obligatorios.');
        } $phone = preg_replace('/[ \-()]/', '', $value);
        if (! is_string($phone) || preg_match('/^(?:3\d{9}|573\d{9}|\+573\d{9})$/D', $phone) !== 1) {
            throw new WompiPrepareValidationException('Completa todos los datos obligatorios.');
        }

return str_starts_with($phone, '+') ? $phone : (str_starts_with($phone, '57') ? '+'.$phone : '+57'.$phone);
    }

    private function document(mixed $value): string
    {
        $document = $this->requiredText($value, 3, 30);
        if (preg_match('/^[\p{L}\p{N} .-]+$/uD', $document) !== 1) {
            throw new WompiPrepareValidationException('Completa todos los datos obligatorios.');
        }

return $document;
    }

    private function postal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        } $postal = $this->normalizedText($value);
        if ($postal === '') {
            return null;
        } $postal = strtoupper($postal);
        if (strlen($postal) < 3 || strlen($postal) > 20 || preg_match('/^[A-Z0-9 -]+$/D', $postal) !== 1) {
            throw new WompiPrepareValidationException('Completa todos los datos obligatorios.');
        }

return $postal;
    }

    private function positiveInteger(mixed $value, int $max): ?int
    {
        if (is_int($value)) {
            return $value >= 1 && $value <= $max ? $value : null;
        } if (! is_string($value) || preg_match('/^[1-9]\d*$/D', $value) !== 1) {
            return null;
        } $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]);

        return $integer === false ? null : $integer;
    }

    /** @return array{public_key: string, integrity_secret: string}|null */
    private function wompiConfiguration(): ?array
    {
        $publicKey = config('services.wompi.public_key');
        $secret = config('services.wompi.integrity_secret');

        return is_string($publicKey) && $publicKey !== '' && is_string($secret) && $secret !== '' ? ['public_key' => $publicKey, 'integrity_secret' => $secret] : null;
    }

    /** @param array{order_id:int,reference:string,payment_status:string,reservation_expires_at:string,total_cop:int,idempotency_replayed:bool} $prepared */
    private function preparedResponse(array $prepared, array $wompi, int $status): JsonResponse
    {
        if ($prepared['total_cop'] > intdiv(PHP_INT_MAX, 100)) {
            throw new \RuntimeException('Amount overflow.');
        }
        $amount = $prepared['total_cop'] * 100;
        $expiration = $prepared['reservation_expires_at'];
        $expiresAt = now()->addHours(self::STATUS_TOKEN_HOURS)->startOfSecond();
        $token = Crypt::encryptString(json_encode(['v' => 1, 'reference' => $prepared['reference'], 'amount_in_cents' => $amount, 'currency' => 'COP', 'exp' => $expiresAt->getTimestamp(), 'nonce' => bin2hex(random_bytes(16))], JSON_THROW_ON_ERROR));

        return response()->json(['order' => ['id' => $prepared['order_id'], 'reference' => $prepared['reference'], 'payment_status' => $prepared['payment_status'], 'total' => $prepared['total_cop']], 'payment' => ['publicKey' => $wompi['public_key'], 'currency' => 'COP', 'amountInCents' => $amount, 'reference' => $prepared['reference'], 'integritySignature' => hash('sha256', $prepared['reference'].$amount.'COP'.$expiration.$wompi['integrity_secret']), 'expirationTime' => $expiration], 'checkout' => ['statusToken' => $token, 'statusTokenExpiresAt' => $expiresAt->toIso8601String()]], $status);
    }

    private function checkoutError(CheckoutGatewayException $exception): string
    {
        if ($exception->remoteCode() === 'RESERVATION_EXPIRED') {
            return 'La reserva anterior venció. Vuelve a continuar al pago para generar una nueva.';
        }

        return match ($exception->remoteCode()) {
            'INSUFFICIENT_STOCK' => 'Uno de los productos ya no tiene inventario suficiente.',
            'PRODUCT_NOT_FOUND', 'PRODUCT_INACTIVE' => 'Uno de los productos ya no está disponible.',
            'IDEMPOTENCY_CONFLICT' => 'La Idempotency-Key ya fue utilizada con otra solicitud.',
            'COUPON_NOT_FOUND' => 'El cupón no existe.',
            'COUPON_INACTIVE', 'COUPON_SCHEDULED', 'COUPON_EXPIRED', 'COUPON_EXHAUSTED' => 'El cupón no está disponible.',
            'COUPON_NOT_APPLICABLE' => 'El cupón no aplica a los productos actuales.',
            'INVALID_COUPON' => 'El cupón no es válido.',
            'INVALID_REQUEST' => 'El pedido contiene productos inválidos.',
            default => 'No es posible preparar el pago en este momento.'
        };
    }
}
class WompiPrepareValidationException extends \RuntimeException {}
