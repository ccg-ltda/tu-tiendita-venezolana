<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\AppsScriptCheckoutClient;
use App\Services\AppsScriptCheckoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class WompiPaymentController extends Controller
{
    private const STATUS_TOKEN_HOURS = 24;

    public function prepare(Request $request, AppsScriptCheckoutClient $client): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $idempotencyKey) !== 1) {
            return response()->json(['error' => 'Idempotency-Key inválida.'], 400);
        }

        try {
            $checkout = $this->normalizeCheckout($request->input('customer'), $request->input('items'), $idempotencyKey);
        } catch (WompiPrepareValidationException $exception) {
            return response()->json(['error' => $exception->getMessage()], 400);
        }

        $wompi = $this->wompiConfiguration();
        if ($wompi === null) {
            return response()->json(['error' => 'No es posible preparar el pago en este momento.'], 503);
        }

        try {
            $prepared = $client->prepareCheckout($checkout);
            return $this->preparedResponse($prepared, $wompi, $prepared['idempotency_replayed'] ? 200 : 201);
        } catch (AppsScriptCheckoutException $exception) {
            return response()->json(['error' => $this->checkoutError($exception)], $exception->status());
        } catch (\Throwable) {
            return response()->json(['error' => 'No es posible preparar el pago en este momento.'], 503);
        }
    }

    /** @return array{customer: array<string, string|null>, items: list<array{product_id: int, quantity: int}>, idempotency_key: string, payload_hash: string} */
    private function normalizeCheckout(mixed $rawCustomer, mixed $rawItems, string $idempotencyKey): array
    {
        if (! is_array($rawCustomer)) throw new WompiPrepareValidationException('Completa todos los datos obligatorios.');
        $customer = [
            'name' => $this->requiredText($rawCustomer['name'] ?? null, 2, 120), 'email' => $this->email($rawCustomer['email'] ?? null),
            'phone' => $this->phone($rawCustomer['phone'] ?? null), 'document' => $this->document($rawCustomer['document'] ?? null),
            'address' => $this->requiredText($rawCustomer['address'] ?? null, 5, 300), 'extra' => $this->nullableText($rawCustomer['extra'] ?? null, 300),
            'city' => $this->requiredText($rawCustomer['city'] ?? null, 2, 100), 'region' => $this->requiredText($rawCustomer['region'] ?? null, 2, 100), 'postal' => $this->postal($rawCustomer['postal'] ?? null),
        ];
        if (! is_array($rawItems) || $rawItems === []) throw new WompiPrepareValidationException('El pedido no tiene productos.');
        $quantities = [];
        foreach ($rawItems as $item) {
            if (! is_array($item) || ($id = $this->positiveInteger($item['id'] ?? null, 2147483647)) === null || ($quantity = $this->positiveInteger($item['qty'] ?? null, 999)) === null) throw new WompiPrepareValidationException('El pedido contiene productos inválidos.');
            $quantities[$id] = ($quantities[$id] ?? 0) + $quantity;
            if ($quantities[$id] > 999) throw new WompiPrepareValidationException('El pedido contiene productos inválidos.');
        }
        ksort($quantities, SORT_NUMERIC);
        if (count($quantities) > 50) throw new WompiPrepareValidationException('El pedido contiene productos inválidos.');
        $items = array_map(static fn (int $id, int $quantity): array => ['product_id' => $id, 'quantity' => $quantity], array_keys($quantities), array_values($quantities));
        $canonical = json_encode(['customer' => $customer, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return ['customer' => $customer, 'items' => $items, 'idempotency_key' => $idempotencyKey, 'payload_hash' => hash('sha256', $canonical)];
    }

    private function normalizedText(mixed $value): string
    {
        if (! is_string($value) || ! class_exists(\Normalizer::class)) throw new WompiPrepareValidationException('Completa todos los datos obligatorios.');
        $text = \Normalizer::normalize($value, \Normalizer::FORM_C);
        if (! is_string($text) || preg_match('/[\x{0}-\x{8}\x{E}-\x{1F}\x{7F}]/u', $text) === 1) throw new WompiPrepareValidationException('Completa todos los datos obligatorios.');
        return trim((string) preg_replace('/[\x{9}-\x{D}\x{20}\x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+/u', ' ', $text));
    }
    private function requiredText(mixed $value, int $min, int $max): string { $text=$this->normalizedText($value); if(mb_strlen($text)<$min||mb_strlen($text)>$max) throw new WompiPrepareValidationException('Completa todos los datos obligatorios.'); return $text; }
    private function nullableText(mixed $value, int $max): ?string { if($value===null)return null; $text=$this->normalizedText($value); if($text==='')return null; if(mb_strlen($text)>$max)throw new WompiPrepareValidationException('Completa todos los datos obligatorios.'); return $text; }
    private function email(mixed $value): string { $email=$this->normalizedText($value); if(preg_match('/[^\x00-\x7F]/',$email)===1||strlen($email)<3||strlen($email)>254||preg_match("/^[A-Z0-9.!#$%&'*+\/=?^_`{|}~-]+@[A-Z0-9](?:[A-Z0-9-]{0,61}[A-Z0-9])?(?:\.[A-Z0-9](?:[A-Z0-9-]{0,61}[A-Z0-9])?)+$/iD",$email)!==1)throw new WompiPrepareValidationException('Completa todos los datos obligatorios.'); return strtolower($email); }
    private function phone(mixed $value): string { if(!is_string($value))throw new WompiPrepareValidationException('Completa todos los datos obligatorios.'); $phone=preg_replace('/[ \-()]/','',$value); if(!is_string($phone)||preg_match('/^(?:3\d{9}|573\d{9}|\+573\d{9})$/D',$phone)!==1)throw new WompiPrepareValidationException('Completa todos los datos obligatorios.'); return str_starts_with($phone,'+')?$phone:(str_starts_with($phone,'57')?'+'.$phone:'+57'.$phone); }
    private function document(mixed $value): string { $document=$this->requiredText($value,3,30); if(preg_match('/^[\p{L}\p{N} .-]+$/uD',$document)!==1)throw new WompiPrepareValidationException('Completa todos los datos obligatorios.'); return $document; }
    private function postal(mixed $value): ?string { if($value===null)return null; $postal=$this->normalizedText($value); if($postal==='')return null; $postal=strtoupper($postal); if(strlen($postal)<3||strlen($postal)>20||preg_match('/^[A-Z0-9 -]+$/D',$postal)!==1)throw new WompiPrepareValidationException('Completa todos los datos obligatorios.'); return $postal; }
    private function positiveInteger(mixed $value, int $max): ?int { if(is_int($value))return $value>=1&&$value<=$max?$value:null; if(!is_string($value)||preg_match('/^[1-9]\d*$/D',$value)!==1)return null; $integer=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>$max]]); return $integer===false?null:$integer; }

    /** @return array{public_key: string, integrity_secret: string}|null */
    private function wompiConfiguration(): ?array { $publicKey=config('services.wompi.public_key');$secret=config('services.wompi.integrity_secret');return is_string($publicKey)&&$publicKey!==''&&is_string($secret)&&$secret!==''?['public_key'=>$publicKey,'integrity_secret'=>$secret]:null; }
    /** @param array{order_id:int,reference:string,payment_status:string,reservation_expires_at:string,total_cop:int,idempotency_replayed:bool} $prepared */
    private function preparedResponse(array $prepared, array $wompi, int $status): JsonResponse
    {
        if($prepared['total_cop']>intdiv(PHP_INT_MAX,100))throw new \RuntimeException('Amount overflow.');
        $amount=$prepared['total_cop']*100;$expiration=$prepared['reservation_expires_at'];$expiresAt=now()->addHours(self::STATUS_TOKEN_HOURS)->startOfSecond();
        $token=Crypt::encryptString(json_encode(['v'=>1,'reference'=>$prepared['reference'],'exp'=>$expiresAt->getTimestamp(),'nonce'=>bin2hex(random_bytes(16))],JSON_THROW_ON_ERROR));
        return response()->json(['order'=>['id'=>$prepared['order_id'],'reference'=>$prepared['reference'],'payment_status'=>$prepared['payment_status'],'total'=>$prepared['total_cop']],'payment'=>['publicKey'=>$wompi['public_key'],'currency'=>'COP','amountInCents'=>$amount,'reference'=>$prepared['reference'],'integritySignature'=>hash('sha256',$prepared['reference'].$amount.'COP'.$expiration.$wompi['integrity_secret']),'expirationTime'=>$expiration],'checkout'=>['statusToken'=>$token,'statusTokenExpiresAt'=>$expiresAt->toIso8601String()]],$status);
    }
    private function checkoutError(AppsScriptCheckoutException $exception): string { return match($exception->remoteCode()) {'INSUFFICIENT_STOCK'=>'Uno de los productos ya no tiene inventario suficiente.','PRODUCT_NOT_FOUND','PRODUCT_INACTIVE'=>'Uno de los productos ya no está disponible.','IDEMPOTENCY_CONFLICT'=>'La Idempotency-Key ya fue utilizada con otra solicitud.','INVALID_REQUEST'=>'El pedido contiene productos inválidos.',default=>'No es posible preparar el pago en este momento.'}; }
}
class WompiPrepareValidationException extends \RuntimeException {}
