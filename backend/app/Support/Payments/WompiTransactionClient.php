<?php

namespace App\Support\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class WompiTransactionClient
{
    /** @var list<string> */
    private const STATUSES = ['PENDING', 'APPROVED', 'DECLINED', 'VOIDED', 'ERROR'];

    /**
     * @return array{id: string, reference: string, status: string, amount_in_cents: int, currency: string, payment_method_type: ?string}
     *
     * @throws WompiTransactionException
     */
    public function fetch(string $transactionId): array
    {
        $this->validateTransactionId($transactionId);
        [$baseUrl, $privateKey] = $this->configuredCredentials();

        try {
            $response = Http::acceptJson()
                ->withToken($privateKey)
                ->connectTimeout(3)
                ->timeout(8)
                ->get($baseUrl.'/transactions/'.rawurlencode($transactionId));
        } catch (ConnectionException $exception) {
            throw new WompiTransactionException('Wompi transaction is unavailable.', previous: $exception);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new WompiTransactionException('Wompi transaction authentication failed.');
        }

        if ($response->status() === 404) {
            throw new WompiTransactionException('Wompi transaction was not found.');
        }

        if ($response->status() === 429) {
            throw new WompiTransactionException('Wompi transaction lookup is rate limited.');
        }

        if ($response->serverError()) {
            throw new WompiTransactionException('Wompi transaction is unavailable.');
        }

        if ($response->status() !== 200) {
            throw new WompiTransactionException('Wompi transaction lookup failed.');
        }

        $data = $response->json('data');
        if (! is_array($data)) {
            throw new WompiTransactionException('Wompi transaction response is invalid.');
        }

        return $this->normalize($data);
    }

    /** @throws WompiTransactionException */
    public function assertConfigured(): void
    {
        $this->configuredCredentials();
    }

    /**
     * @return array{0: string, 1: string}
     *
     * @throws WompiTransactionException
     */
    private function configuredCredentials(): array
    {
        $environment = config('services.wompi.environment');
        $baseUrl = config('services.wompi.base_url');
        $privateKey = config('services.wompi.private_key');

        if (! is_string($environment) || ! is_string($baseUrl) || ! is_string($privateKey)
            || $environment === '' || $baseUrl === '' || $privateKey === '') {
            throw new WompiTransactionException('Wompi transaction configuration is unavailable.');
        }

        $expected = match ($environment) {
            'sandbox' => ['https://sandbox.wompi.co/v1', 'prv_test_'],
            'production' => ['https://production.wompi.co/v1', 'prv_prod_'],
            default => null,
        };

        if ($expected === null || $baseUrl !== $expected[0] || ! str_starts_with($privateKey, $expected[1])) {
            throw new WompiTransactionException('Wompi transaction configuration is invalid.');
        }

        return [$baseUrl, $privateKey];
    }

    /** @throws WompiTransactionException */
    private function validateTransactionId(string $transactionId): void
    {
        if ($transactionId === '' || strlen($transactionId) > 255 || ! preg_match('/^[A-Za-z0-9_-]+$/', $transactionId)) {
            throw new WompiTransactionException('Wompi transaction id is invalid.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{id: string, reference: string, status: string, amount_in_cents: int, currency: string, payment_method_type: ?string}
     *
     * @throws WompiTransactionException
     */
    private function normalize(array $data): array
    {
        $paymentMethod = $data['payment_method_type'] ?? null;

        if (! is_string($data['id'] ?? null) || $data['id'] === ''
            || ! is_string($data['reference'] ?? null) || $data['reference'] === ''
            || ! is_string($data['status'] ?? null) || ! in_array($data['status'], self::STATUSES, true)
            || ! is_int($data['amount_in_cents'] ?? null) || $data['amount_in_cents'] < 1
            || ! is_string($data['currency'] ?? null) || $data['currency'] !== 'COP'
            || ($paymentMethod !== null && (! is_string($paymentMethod) || $paymentMethod === ''))) {
            throw new WompiTransactionException('Wompi transaction response is invalid.');
        }

        return [
            'id' => $data['id'],
            'reference' => $data['reference'],
            'status' => $data['status'],
            'amount_in_cents' => $data['amount_in_cents'],
            'currency' => $data['currency'],
            'payment_method_type' => $paymentMethod,
        ];
    }
}
