<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\AppsScriptCheckoutClient;
use App\Services\AppsScriptCheckoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class WompiPaymentStatusController extends Controller
{
    private const UNAVAILABLE = ['error' => 'Checkout status unavailable.'];

    public function show(Request $request, AppsScriptCheckoutClient $client): JsonResponse
    {
        $reference = $this->tokenReference($request->header('X-Checkout-Status-Token'));
        if ($reference === null) {
            return $this->unavailable();
        }

        try {
            $checkout = $client->getCheckoutStatus($reference);
        } catch (AppsScriptCheckoutException $exception) {
            return $this->unavailable($exception->status());
        } catch (\Throwable) {
            return $this->unavailable(503);
        }

        if ($checkout['reference'] !== $reference) {
            return $this->unavailable(502);
        }

        return response()
            ->json(['checkout' => [
                'status' => $checkout['payment_status'] === 'APPROVED' ? 'APPROVED' : 'PENDING',
                'reservation' => $this->reservationStatus($checkout),
                // A Wompi event is the most precise update; otherwise use the durable write timestamp.
                'statusUpdatedAt' => $checkout['payment_last_event_at'] ?? $checkout['updated_at'],
            ]])
            ->header('Cache-Control', 'no-store');
    }

    private function tokenReference(mixed $token): ?string
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($data)
            || ($data['v'] ?? null) !== 1
            || ! $this->validReference($data['reference'] ?? null)
            || ! is_int($data['exp'] ?? null)
            || $data['exp'] <= now('UTC')->getTimestamp()
            || ! is_string($data['nonce'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/D', $data['nonce']) !== 1) {
            return null;
        }

        return $data['reference'];
    }

    /** @param array{payment_status: string, reservation_status: string, reservation_expires_at: string|null} $checkout */
    private function reservationStatus(array $checkout): string
    {
        return match ($checkout['reservation_status']) {
            'ACTIVE' => $checkout['payment_status'] === 'PENDING'
                && $checkout['reservation_expires_at'] !== null
                && $this->reservationExpired($checkout['reservation_expires_at'])
                    ? 'EXPIRED'
                    : 'ACTIVE',
            'RELEASED' => 'RELEASED',
            'CONSUMED' => 'CONSUMED',
            default => 'INVALID',
        };
    }

    private function reservationExpired(string $reservationExpiresAt): bool
    {
        $expiresAt = new \DateTimeImmutable($reservationExpiresAt);
        $now = now('UTC');
        $expiresSeconds = $expiresAt->getTimestamp();
        $nowSeconds = $now->getTimestamp();

        return $expiresSeconds < $nowSeconds
            || ($expiresSeconds === $nowSeconds && (int) $expiresAt->format('v') <= (int) $now->format('v'));
    }

    private function validReference(mixed $reference): bool
    {
        return is_string($reference)
            && $reference === trim($reference)
            && strlen($reference) >= 1
            && strlen($reference) <= 120
            && preg_match('/[\x00-\x1F\x7F]/', $reference) !== 1;
    }

    private function unavailable(int $status = 404): JsonResponse
    {
        return response()->json(self::UNAVAILABLE, $status)->header('Cache-Control', 'no-store');
    }
}
