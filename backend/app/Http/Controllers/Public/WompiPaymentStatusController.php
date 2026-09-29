<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Support\Payments\WompiTransactionClient;
use App\Support\Payments\WompiTransactionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class WompiPaymentStatusController extends Controller
{
    private const UNAVAILABLE = ['error' => 'Payment status temporarily unavailable.'];

    public function show(Request $request, WompiTransactionClient $wompi): JsonResponse
    {
        $token = $this->statusToken($request->header('X-Checkout-Status-Token'));
        $transactionId = $request->header('X-Wompi-Transaction-Id');
        if ($token === null || ! is_string($transactionId) || $transactionId === '') {
            return $this->unavailable();
        }

        try {
            $transaction = $wompi->fetch($transactionId);
        } catch (WompiTransactionException $exception) {
            Log::warning('Wompi payment status lookup failed.', ['reference' => $token['reference'], 'reason' => $exception->getMessage()]);

            return $this->unavailable(503);
        } catch (\Throwable) {
            Log::error('Wompi payment status lookup failed unexpectedly.', ['reference' => $token['reference']]);

            return $this->unavailable(503);
        }

        if ($transaction['id'] !== $transactionId || $transaction['reference'] !== $token['reference'] || $transaction['amount_in_cents'] !== $token['amount_in_cents'] || $transaction['currency'] !== $token['currency']) {
            return $this->unavailable(502);
        }

        return response()->json(['checkout' => ['status' => $transaction['status']]])->header('Cache-Control', 'no-store');
    }

    /** @return array{reference: string, amount_in_cents: int, currency: string}|null */
    private function statusToken(mixed $token): ?array
    {
        if (! is_string($token) || $token === '') {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        if (! is_array($data) || ($data['v'] ?? null) !== 1 || ! $this->validReference($data['reference'] ?? null) || ! is_int($data['amount_in_cents'] ?? null) || $data['amount_in_cents'] < 1 || ($data['currency'] ?? null) !== 'COP' || ! is_int($data['exp'] ?? null) || $data['exp'] <= now('UTC')->getTimestamp() || ! is_string($data['nonce'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $data['nonce']) !== 1) {
            return null;
        }

        return ['reference' => $data['reference'], 'amount_in_cents' => $data['amount_in_cents'], 'currency' => $data['currency']];
    }

    private function validReference(mixed $reference): bool
    {
        return is_string($reference) && $reference === trim($reference) && strlen($reference) >= 1 && strlen($reference) <= 120 && preg_match('/[\x00-\x1F\x7F]/', $reference) !== 1;
    }

    private function unavailable(int $status = 404): JsonResponse
    {
        return response()->json(self::UNAVAILABLE, $status)->header('Cache-Control', 'no-store');
    }
}
