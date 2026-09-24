<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AppsScriptCheckoutClient
{
    /**
     * @param array{customer: array<string, string|null>, items: list<array{product_id: int, quantity: int}>, idempotency_key: string, payload_hash: string} $checkout
     * @return array{order_id: int, reference: string, status: string, payment_status: string, reservation_status: string, reservation_expires_at: string, total_cop: int, created_at: string, revision: int, idempotency_replayed: bool}
     *
     * @throws AppsScriptCheckoutException
     */
    public function prepareCheckout(array $checkout): array
    {
        $url = config('services.apps_script.url');
        $apiKey = config('services.apps_script.api_key');

        if (! is_string($url) || $url === '' || ! is_string($apiKey) || $apiKey === '') {
            throw new AppsScriptCheckoutException(503);
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout(3)
                ->timeout(30)
                ->post($url, [
                    'action' => 'prepare_checkout',
                    'api_key' => $apiKey,
                    'idempotency_key' => $checkout['idempotency_key'],
                    'payload_hash' => $checkout['payload_hash'],
                    'customer' => $checkout['customer'],
                    'items' => $checkout['items'],
                ]);
        } catch (ConnectionException $exception) {
            throw new AppsScriptCheckoutException($this->isTimeout($exception) ? 504 : 503);
        } catch (\Throwable) {
            throw new AppsScriptCheckoutException(502);
        }

        if (! $response->successful()) {
            throw new AppsScriptCheckoutException(502);
        }

        try {
            $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AppsScriptCheckoutException(502);
        }

        if (! is_array($payload) || ! array_key_exists('ok', $payload) || ! is_bool($payload['ok'])) {
            throw new AppsScriptCheckoutException(502);
        }

        if ($payload['ok'] === false) {
            $code = is_array($payload['error'] ?? null) && is_string($payload['error']['code'] ?? null)
                ? $payload['error']['code']
                : null;

            throw new AppsScriptCheckoutException($this->remoteStatus($code), $code);
        }

        return $this->normalizeData($payload['data'] ?? null);
    }

    /**
     * @param array{id: string, reference: string, status: string, payment_method: string, amount_in_cents: int, currency: string, event_occurred_at: string} $transaction
     * @return array{order_id: int, payment_attempt_id: int, payment_event_replayed: bool, event_result: string, status: string, payment_status: string, reservation_status: string, revision: int}
     *
     * @throws AppsScriptCheckoutException
     */
    public function recordPaymentEvent(array $transaction): array
    {
        $payload = $this->post('record_payment_event', ['transaction' => $transaction]);

        return $this->normalizePaymentEventData($payload['data'] ?? null);
    }

    /**
     * @return array{order_id: int, reference: string, status: string, payment_status: string, reservation_status: string, reservation_expires_at: string|null, paid_at: string|null, payment_last_event_at: string|null, created_at: string, updated_at: string, revision: int}
     *
     * @throws AppsScriptCheckoutException
     */
    public function getCheckoutStatus(string $reference): array
    {
        if (! $this->reference($reference)) {
            throw new AppsScriptCheckoutException(400);
        }

        $payload = $this->post('get_checkout_status', ['reference' => $reference]);

        return $this->normalizeCheckoutStatusData($payload['data'] ?? null);
    }

    /** @return array{orders: list<array{id: int, reference: string, status: string, customer_name: string, total: int, created_at: string}>, pagination: array{current_page: int, per_page: int, total: int, last_page: int}} */
    public function adminListOrders(int $page, int $perPage): array
    {
        if ($page < 1 || $perPage < 1 || $perPage > 100) {
            throw new AppsScriptCheckoutException(400);
        }

        $payload = $this->postWithRetries('admin_list_orders', ['page' => $page, 'per_page' => $perPage]);

        $result = $this->normalizeAdminListData($payload['data'] ?? null);
        if ($result['pagination']['current_page'] !== $page || $result['pagination']['per_page'] !== $perPage) {
            throw new AppsScriptCheckoutException(502);
        }

        return $result;
    }

    /** @return array{id: int, reference: string, status: string, customer_name: string, customer_email: string, customer_phone: string, customer_document: string, address: string, extra: string|null, city: string, region: string, postal: string|null, total: int, created_at: string, items: list<array{id: int, product_id: int, product_name: string, unit_price: int, quantity: int}>} */
    public function adminGetOrder(int $orderId): array
    {
        if ($orderId < 1 || $orderId > 2147483647) {
            throw new AppsScriptCheckoutException(400);
        }

        $payload = $this->postWithRetries('admin_get_order', ['order_id' => $orderId]);

        return $this->normalizeAdminOrderData($payload['data'] ?? null);
    }

    /** @return array{order_id: int, status: string, updated_at: string, revision: int, idempotency_replayed: bool} */
    public function adminUpdateOrderStatus(int $orderId, string $status): array
    {
        if ($orderId < 1 || $orderId > 2147483647 || ! in_array($status, ['PENDING', 'PROCESSING', 'READY', 'SHIPPED', 'DELIVERED', 'CANCELLED'], true)) {
            throw new AppsScriptCheckoutException(400);
        }
        $data = $this->post('admin_update_order_status', ['order_id' => $orderId, 'status' => $status])['data'] ?? null;
        if (! is_array($data) || ! $this->integer($data['order_id'] ?? null, 1, 2147483647)
            || ! in_array($data['status'] ?? null, ['PENDING', 'PROCESSING', 'READY', 'SHIPPED', 'DELIVERED', 'CANCELLED'], true)
            || ! $this->timestamp($data['updated_at'] ?? null)
            || ! $this->integer($data['revision'] ?? null, 1, 2147483647)
            || ! is_bool($data['idempotency_replayed'] ?? null)) throw new AppsScriptCheckoutException(502);
        return $data;
    }

    /** @return list<array{order_id: int, reference: string, revision: int, reservation_expires_at: string, total_cop: int, payment_attempts: list<array{wompi_transaction_id: string, status: string, amount_in_cents: int, currency: string, updated_at: string}>}> */
    public function getExpiredReservationCandidates(int $limit = 20): array
    {
        if ($limit < 1 || $limit > 50) {
            throw new AppsScriptCheckoutException(400);
        }

        $payload = $this->postWithRetries('release_expired_reservation', ['mode' => 'candidates', 'limit' => $limit]);
        $data = $payload['data'] ?? null;
        if (! is_array($data) || ! array_key_exists('candidates', $data) || ! is_array($data['candidates'])) {
            throw new AppsScriptCheckoutException(502);
        }

        if (count($data['candidates']) > $limit) {
            throw new AppsScriptCheckoutException(502);
        }

        $candidates = array_map(fn (mixed $candidate): array => $this->normalizeReleaseCandidate($candidate), $data['candidates']);
        $references = array_column($candidates, 'reference');
        if (count($references) !== count(array_unique($references))) {
            throw new AppsScriptCheckoutException(502);
        }

        return $candidates;
    }

    /**
     * @param list<array{reference: string, expected_revision: int, verified_final_attempts: list<array{wompi_transaction_id: string, status: string, checked_at: string}>}> $releases
     * @return array{released: list<array{order_id: int, reference: string, reservation_status: string, revision: int, idempotency_replayed: bool}>, held: list<array{reference: string, reason: string}>, review_required: list<array{reference: string, reason: string}>}
     */
    public function commitExpiredReservations(array $releases): array
    {
        if (count($releases) < 1 || count($releases) > 20) {
            throw new AppsScriptCheckoutException(400);
        }
        $seen = [];
        foreach ($releases as $release) {
            if (! is_array($release) || ! $this->reference($release['reference'] ?? null)
                || ! $this->integer($release['expected_revision'] ?? null, 1, 2147483647)
                || ! is_array($release['verified_final_attempts'] ?? null)
                || isset($seen[$release['reference']])) {
                throw new AppsScriptCheckoutException(400);
            }
            $seen[$release['reference']] = true;
            foreach ($release['verified_final_attempts'] as $attempt) {
                if (! is_array($attempt) || ! $this->text($attempt['wompi_transaction_id'] ?? null, 1, 200)
                    || ! in_array($attempt['status'] ?? null, ['DECLINED', 'VOIDED', 'ERROR'], true)
                    || ! $this->timestamp($attempt['checked_at'] ?? null)) {
                    throw new AppsScriptCheckoutException(400);
                }
            }
        }

        $payload = $this->post('release_expired_reservation', ['mode' => 'commit', 'releases' => $releases]);
        $data = $payload['data'] ?? null;
        if (! is_array($data) || ! is_array($data['released'] ?? null) || ! is_array($data['held'] ?? null) || ! is_array($data['review_required'] ?? null)) {
            throw new AppsScriptCheckoutException(502);
        }

        return [
            'released' => array_map(fn (mixed $result): array => $this->normalizeReleasedReservation($result), $data['released']),
            'held' => array_map(fn (mixed $result): array => $this->normalizeReleaseResult($result), $data['held']),
            'review_required' => array_map(fn (mixed $result): array => $this->normalizeReleaseResult($result), $data['review_required']),
        ];
    }

    /** @param array<string, mixed> $data */
    private function postWithRetries(string $action, array $data): array
    {
        foreach ([1, 2, 3] as $attempt) {
            try {
                return $this->post($action, $data, $attempt);
            } catch (AppsScriptCheckoutException $exception) {
                if (! $exception->retryable() || $attempt === 3) {
                    throw $exception;
                }

                $delayMilliseconds = $attempt === 1 ? 500 : 1500;
                Log::info('apps_script_request_retrying', [
                    'action' => $action,
                    'attempt' => $attempt,
                    'next_attempt' => $attempt + 1,
                    'delay_ms' => $delayMilliseconds,
                    'status' => $exception->status(),
                ]);
                usleep($delayMilliseconds * 1000);
            }
        }

        throw new \LogicException('Apps Script retry loop ended unexpectedly.');
    }

    private function post(string $action, array $data, int $attempt = 1): array
    {
        $url = config('services.apps_script.url');
        $apiKey = config('services.apps_script.api_key');

        if (! is_string($url) || $url === '' || ! is_string($apiKey) || $apiKey === '') {
            throw new AppsScriptCheckoutException(503);
        }

        $startedAt = microtime(true);
        $initialUrl = $this->safeUrl($url);
        $initialHost = $this->urlHost($url);
        Log::debug('apps_script_request_started', [
            'action' => $action,
            'attempt' => $attempt,
            'initial_url' => $initialUrl,
            'initial_host' => $initialHost,
            ...$this->dnsContext($initialHost),
        ]);

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout(3)
                ->timeout(30)
                ->post($url, ['action' => $action, 'api_key' => $apiKey, ...$data]);
        } catch (ConnectionException $exception) {
            $isTimeout = $this->isTimeout($exception);
            Log::warning('apps_script_request_exception', [
                'action' => $action,
                'attempt' => $attempt,
                'exception_class' => $exception::class,
                'previous_exception_class' => $exception->getPrevious() ? $exception->getPrevious()::class : null,
                'curl_errno' => $this->curlErrno($exception),
                'failure_host' => $this->exceptionHost($exception) ?? $initialHost,
                'duration_ms' => $this->durationMilliseconds($startedAt),
                'exception_kind' => $this->connectionFailureKind($exception),
                'message' => $this->sanitizedExceptionMessage($exception),
                'status' => $isTimeout ? 504 : 503,
            ]);

            throw new AppsScriptCheckoutException($isTimeout ? 504 : 503, null, true);
        } catch (\Throwable $exception) {
            Log::warning('apps_script_request_exception', [
                'action' => $action,
                'attempt' => $attempt,
                'exception_class' => $exception::class,
                'previous_exception_class' => $exception->getPrevious() ? $exception->getPrevious()::class : null,
                'failure_host' => $this->exceptionHost($exception) ?? $initialHost,
                'duration_ms' => $this->durationMilliseconds($startedAt),
                'exception_kind' => 'unexpected',
                'message' => $this->sanitizedExceptionMessage($exception),
            ]);

            throw new AppsScriptCheckoutException(502);
        }

        $responseForLog = json_decode($response->body(), true);
        $responseIsJson = is_array($responseForLog);
        $remoteCode = $responseIsJson
            && is_array($responseForLog['error'] ?? null)
            && is_string($responseForLog['error']['code'] ?? null)
            ? $responseForLog['error']['code']
            : null;

        Log::debug('apps_script_response_received', [
            'action' => $action,
            'attempt' => $attempt,
            'http_status' => $response->status(),
            'content_type' => $response->header('Content-Type'),
            'final_url' => $this->safeUrl($response->effectiveUri()),
            'duration_ms' => $this->durationMilliseconds($startedAt),
            'body_looks_json' => $responseIsJson,
            'has_ok' => $responseIsJson && array_key_exists('ok', $responseForLog),
            'remote_code' => $remoteCode,
        ]);

        if (! $response->successful()) {
            throw new AppsScriptCheckoutException(502, null, $this->retryableHttpResponse($response->status(), $response->header('Content-Type'), $response->body()));
        }

        try {
            $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AppsScriptCheckoutException(502, null, $this->isHtmlResponse($response->header('Content-Type'), $response->body()));
        }

        if (! is_array($payload) || ! array_key_exists('ok', $payload) || ! is_bool($payload['ok'])) {
            throw new AppsScriptCheckoutException(502);
        }

        if ($payload['ok'] === false) {
            $code = is_array($payload['error'] ?? null) && is_string($payload['error']['code'] ?? null)
                ? $payload['error']['code']
                : null;

            throw new AppsScriptCheckoutException($this->remoteStatus($code), $code);
        }

        return $payload;
    }

    private function retryableHttpResponse(int $status, ?string $contentType, string $body): bool
    {
        return in_array($status, [502, 503, 504], true)
            || ($status === 404 && $this->isHtmlResponse($contentType, $body));
    }

    private function isHtmlResponse(?string $contentType, string $body): bool
    {
        return is_string($contentType) && str_contains(mb_strtolower($contentType), 'text/html')
            || str_starts_with(ltrim($body), '<');
    }

    private function durationMilliseconds(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function safeUrl(mixed $uri): ?string
    {
        try {
            $parts = parse_url((string) $uri);
            if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
                return null;
            }

            return $parts['scheme'].'://'.$parts['host'].($parts['path'] ?? '');
        } catch (\Throwable) {
            return null;
        }
    }

    private function urlHost(mixed $uri): ?string
    {
        try {
            $parts = parse_url((string) $uri);

            return is_array($parts) && isset($parts['host']) && is_string($parts['host']) ? $parts['host'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{dns_status: string, dns_addresses: list<string>} */
    private function dnsContext(?string $host): array
    {
        if ($host === null || $host === '') {
            return ['dns_status' => 'host_unavailable', 'dns_addresses' => []];
        }

        $addresses = gethostbynamel($host);

        return is_array($addresses)
            ? ['dns_status' => 'resolved', 'dns_addresses' => array_values(array_unique($addresses))]
            : ['dns_status' => 'unresolved', 'dns_addresses' => []];
    }

    private function curlErrno(\Throwable $exception): ?int
    {
        if (preg_match('/curl error\s+(\d+)/i', $this->exceptionMessages($exception), $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function exceptionHost(\Throwable $exception): ?string
    {
        if (preg_match_all('#https?://[^\s\)]+#i', $this->exceptionMessages($exception), $matches) < 1) {
            return null;
        }

        return $this->urlHost(end($matches[0]));
    }

    private function connectionFailureKind(\Throwable $exception): string
    {
        $message = mb_strtolower($this->exceptionMessages($exception));

        return match (true) {
            str_contains($message, 'timeout') || str_contains($message, 'timed out') || str_contains($message, 'curl error 28') => 'timeout',
            str_contains($message, 'could not resolve host') || str_contains($message, 'getaddrinfo') || str_contains($message, 'name or service not known') => 'dns',
            str_contains($message, 'proxy') => 'proxy',
            str_contains($message, 'ssl') || str_contains($message, 'tls') || str_contains($message, 'certificate') || str_contains($message, 'schannel') => 'tls_ssl',
            str_contains($message, 'redirect') => 'redirect',
            str_contains($message, 'connection reset') || str_contains($message, 'recv failure') || str_contains($message, 'broken pipe') => 'connection_reset',
            str_contains($message, 'failed to connect') || str_contains($message, 'connection refused') || str_contains($message, "couldn't connect") || str_contains($message, 'network is unreachable') || str_contains($message, 'no route to host') => 'tcp_connect',
            default => 'other',
        };
    }

    private function sanitizedExceptionMessage(\Throwable $exception): string
    {
        $message = preg_replace_callback(
            '#https?://[^\s\)]+#i',
            fn (array $match): string => $this->safeUrl($match[0]) ?? '[url]',
            $this->exceptionMessages($exception),
        );

        return mb_substr(trim($message ?? 'Apps Script request failed.'), 0, 500);
    }

    private function exceptionMessages(\Throwable $exception): string
    {
        $messages = [];
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $messages[] = $current->getMessage();
        }

        return implode(' | ', $messages);
    }

    /** @return array{order_id: int, reference: string, status: string, payment_status: string, reservation_status: string, reservation_expires_at: string, total_cop: int, created_at: string, revision: int, idempotency_replayed: bool} */
    private function normalizeData(mixed $data): array
    {
        if (! is_array($data)
            || ! $this->integer($data['order_id'] ?? null, 1, 2147483647)
            || ! $this->text($data['reference'] ?? null, 1, 120)
            || ! in_array($data['status'] ?? null, ['PENDING'], true)
            || ! in_array($data['payment_status'] ?? null, ['PENDING'], true)
            || ! in_array($data['reservation_status'] ?? null, ['ACTIVE'], true)
            || ! $this->timestamp($data['reservation_expires_at'] ?? null)
            || ! $this->integer($data['total_cop'] ?? null, 0, intdiv(PHP_INT_MAX, 100))
            || ! $this->timestamp($data['created_at'] ?? null)
            || ! $this->integer($data['revision'] ?? null, 1, 2147483647)
            || ! is_bool($data['idempotency_replayed'] ?? null)) {
            throw new AppsScriptCheckoutException(502);
        }

        return [
            'order_id' => $data['order_id'],
            'reference' => $data['reference'],
            'status' => $data['status'],
            'payment_status' => $data['payment_status'],
            'reservation_status' => $data['reservation_status'],
            'reservation_expires_at' => $data['reservation_expires_at'],
            'total_cop' => $data['total_cop'],
            'created_at' => $data['created_at'],
            'revision' => $data['revision'],
            'idempotency_replayed' => $data['idempotency_replayed'],
        ];
    }

    /** @return array{order_id: int, payment_attempt_id: int, payment_event_replayed: bool, event_result: string, status: string, payment_status: string, reservation_status: string, revision: int} */
    private function normalizePaymentEventData(mixed $data): array
    {
        if (! is_array($data)
            || ! $this->integer($data['order_id'] ?? null, 1, 2147483647)
            || ! $this->integer($data['payment_attempt_id'] ?? null, 1, 2147483647)
            || ! is_bool($data['payment_event_replayed'] ?? null)
            || ! in_array($data['event_result'] ?? null, ['RECORDED', 'APPROVED', 'STALE_IGNORED', 'PAYMENT_REVIEW_REQUIRED'], true)
            || ! in_array($data['status'] ?? null, ['PENDING', 'PAYMENT_REVIEW_REQUIRED'], true)
            || ! in_array($data['payment_status'] ?? null, ['PENDING', 'APPROVED'], true)
            || ! in_array($data['reservation_status'] ?? null, ['ACTIVE', 'CONSUMED', 'RELEASED'], true)
            || ! $this->integer($data['revision'] ?? null, 1, 2147483647)) {
            throw new AppsScriptCheckoutException(502);
        }

        return [
            'order_id' => $data['order_id'],
            'payment_attempt_id' => $data['payment_attempt_id'],
            'payment_event_replayed' => $data['payment_event_replayed'],
            'event_result' => $data['event_result'],
            'status' => $data['status'],
            'payment_status' => $data['payment_status'],
            'reservation_status' => $data['reservation_status'],
            'revision' => $data['revision'],
        ];
    }

    /** @return array{order_id: int, reference: string, status: string, payment_status: string, reservation_status: string, reservation_expires_at: string|null, paid_at: string|null, payment_last_event_at: string|null, created_at: string, updated_at: string, revision: int} */
    private function normalizeCheckoutStatusData(mixed $data): array
    {
        if (! is_array($data)
            || ! $this->integer($data['order_id'] ?? null, 1, 2147483647)
            || ! $this->reference($data['reference'] ?? null)
            || ! in_array($data['status'] ?? null, ['PENDING', 'PAYMENT_REVIEW_REQUIRED', 'CONSISTENCY_REVIEW_REQUIRED', 'RESERVATION_PREPARING', 'RESERVATION_ABORTED'], true)
            || ! in_array($data['payment_status'] ?? null, ['PENDING', 'APPROVED'], true)
            || ! in_array($data['reservation_status'] ?? null, ['ACTIVE', 'CONSUMED', 'RELEASED'], true)
            || ! array_key_exists('reservation_expires_at', $data)
            || ! array_key_exists('paid_at', $data)
            || ! array_key_exists('payment_last_event_at', $data)
            || ! $this->nullableTimestamp($data['reservation_expires_at'] ?? null)
            || ! $this->nullableTimestamp($data['paid_at'] ?? null)
            || ! $this->nullableTimestamp($data['payment_last_event_at'] ?? null)
            || ! $this->timestamp($data['created_at'] ?? null)
            || ! $this->timestamp($data['updated_at'] ?? null)
            || ! $this->integer($data['revision'] ?? null, 0, 2147483647)) {
            throw new AppsScriptCheckoutException(502);
        }

        return [
            'order_id' => $data['order_id'],
            'reference' => $data['reference'],
            'status' => $data['status'],
            'payment_status' => $data['payment_status'],
            'reservation_status' => $data['reservation_status'],
            'reservation_expires_at' => $data['reservation_expires_at'],
            'paid_at' => $data['paid_at'],
            'payment_last_event_at' => $data['payment_last_event_at'],
            'created_at' => $data['created_at'],
            'updated_at' => $data['updated_at'],
            'revision' => $data['revision'],
        ];
    }

    /** @return array{orders: list<array{id: int, reference: string, status: string, customer_name: string, total: int, created_at: string}>, pagination: array{current_page: int, per_page: int, total: int, last_page: int}} */
    private function normalizeAdminListData(mixed $data): array
    {
        if (! is_array($data) || ! is_array($data['orders'] ?? null) || ! is_array($data['pagination'] ?? null)) {
            throw new AppsScriptCheckoutException(502);
        }

        $pagination = $data['pagination'];
        if (! $this->integer($pagination['current_page'] ?? null, 1, 2147483647)
            || ! $this->integer($pagination['per_page'] ?? null, 1, 100)
            || ! $this->integer($pagination['total'] ?? null, 0, 2147483647)
            || ! $this->integer($pagination['last_page'] ?? null, 1, 2147483647)
            || $pagination['last_page'] !== max(1, (int) ceil($pagination['total'] / $pagination['per_page']))) {
            throw new AppsScriptCheckoutException(502);
        }

        $orders = array_map(function (mixed $order): array {
            if (! is_array($order)
                || ! $this->integer($order['id'] ?? null, 1, 2147483647)
                || ! $this->reference($order['reference'] ?? null)
                || ! in_array($order['status'] ?? null, ['PENDING', 'PROCESSING', 'READY', 'SHIPPED', 'DELIVERED', 'CANCELLED'], true)
                || (array_key_exists('payment_status', $order) && ! in_array($order['payment_status'], ['PENDING', 'APPROVED', 'DECLINED', 'VOIDED', 'ERROR'], true))
                || ! $this->text($order['customer_name'] ?? null, 1, 120)
                || ! $this->integer($order['total'] ?? null, 0, 2147483647)
                || ! $this->timestamp($order['created_at'] ?? null)) {
                throw new AppsScriptCheckoutException(502);
            }

            return [
                'id' => $order['id'],
                'reference' => $order['reference'],
                'status' => $order['status'],
                ...(array_key_exists('payment_status', $order) ? ['payment_status' => $order['payment_status']] : []),
                'customer_name' => $order['customer_name'],
                'total' => $order['total'],
                'created_at' => $order['created_at'],
            ];
        }, $data['orders']);

        if (count($orders) > $pagination['per_page']) {
            throw new AppsScriptCheckoutException(502);
        }

        $ids = array_column($orders, 'id');
        if (count($ids) !== count(array_unique($ids))) {
            throw new AppsScriptCheckoutException(502);
        }
        for ($index = 1; $index < count($ids); $index++) {
            if ($ids[$index - 1] <= $ids[$index]) {
                throw new AppsScriptCheckoutException(502);
            }
        }

        return [
            'orders' => $orders,
            'pagination' => [
                'current_page' => $pagination['current_page'],
                'per_page' => $pagination['per_page'],
                'total' => $pagination['total'],
                'last_page' => $pagination['last_page'],
            ],
        ];
    }

    /** @return array{id: int, reference: string, status: string, customer_name: string, customer_email: string, customer_phone: string, customer_document: string, address: string, extra: string|null, city: string, region: string, postal: string|null, total: int, created_at: string, items: list<array{id: int, product_id: int, product_name: string, unit_price: int, quantity: int}>} */
    private function normalizeAdminOrderData(mixed $data): array
    {
        $order = is_array($data) ? ($data['order'] ?? null) : null;
        if (! is_array($order)
            || ! $this->integer($order['id'] ?? null, 1, 2147483647)
            || ! $this->reference($order['reference'] ?? null)
            || ! in_array($order['status'] ?? null, ['PENDING', 'PROCESSING', 'READY', 'SHIPPED', 'DELIVERED', 'CANCELLED'], true)
            || (array_key_exists('payment_status', $order) && ! in_array($order['payment_status'], ['PENDING', 'APPROVED', 'DECLINED', 'VOIDED', 'ERROR'], true))
            || (array_key_exists('reservation_status', $order) && ! in_array($order['reservation_status'], ['ACTIVE', 'CONSUMED', 'RELEASED'], true))
            || (array_key_exists('paid_at', $order) && ! $this->nullableTimestamp($order['paid_at']))
            || (array_key_exists('payment', $order) && $order['payment'] !== null && ! is_array($order['payment']))
            || ! $this->text($order['customer_name'] ?? null, 1, 120)
            || ! $this->text($order['customer_email'] ?? null, 3, 254)
            || ! $this->text($order['customer_phone'] ?? null, 1, 50)
            || ! $this->text($order['customer_document'] ?? null, 1, 50)
            || ! $this->text($order['address'] ?? null, 1, 300)
            || ! $this->nullableText($order['extra'] ?? null, 300)
            || ! $this->text($order['city'] ?? null, 1, 100)
            || ! $this->text($order['region'] ?? null, 1, 100)
            || ! $this->nullableText($order['postal'] ?? null, 30)
            || ! $this->integer($order['total'] ?? null, 0, 2147483647)
            || ! $this->timestamp($order['created_at'] ?? null)
            || ! is_array($order['items'] ?? null)) {
            throw new AppsScriptCheckoutException(502);
        }

        $items = array_map(function (mixed $item): array {
            if (! is_array($item)
                || ! $this->integer($item['id'] ?? null, 1, 2147483647)
                || ! $this->integer($item['product_id'] ?? null, 1, 2147483647)
                || ! $this->text($item['product_name'] ?? null, 1, 500)
                || ! $this->integer($item['unit_price'] ?? null, 0, 2147483647)
                || ! $this->integer($item['quantity'] ?? null, 1, 999)) {
                throw new AppsScriptCheckoutException(502);
            }

            return [
                'id' => $item['id'],
                'product_id' => $item['product_id'],
                'product_name' => $item['product_name'],
                'unit_price' => $item['unit_price'],
                'quantity' => $item['quantity'],
            ];
        }, $order['items']);

        $ids = array_column($items, 'id');
        if (count($ids) !== count(array_unique($ids))) {
            throw new AppsScriptCheckoutException(502);
        }

        return [
            'id' => $order['id'],
            'reference' => $order['reference'],
            'status' => $order['status'],
            ...(array_key_exists('payment_status', $order) ? ['payment_status' => $order['payment_status']] : []),
            ...(array_key_exists('reservation_status', $order) ? ['reservation_status' => $order['reservation_status']] : []),
            ...(array_key_exists('paid_at', $order) ? ['paid_at' => $order['paid_at']] : []),
            ...(array_key_exists('payment', $order) ? ['payment' => $order['payment']] : []),
            'customer_name' => $order['customer_name'],
            'customer_email' => $order['customer_email'],
            'customer_phone' => $order['customer_phone'],
            'customer_document' => $order['customer_document'],
            'address' => $order['address'],
            'extra' => $order['extra'],
            'city' => $order['city'],
            'region' => $order['region'],
            'postal' => $order['postal'],
            'total' => $order['total'],
            'created_at' => $order['created_at'],
            'items' => $items,
        ];
    }

    private function normalizeReleaseCandidate(mixed $candidate): array
    {
        if (! is_array($candidate)
            || ! $this->integer($candidate['order_id'] ?? null, 1, 2147483647)
            || ! $this->reference($candidate['reference'] ?? null)
            || ! $this->integer($candidate['revision'] ?? null, 1, 2147483647)
            || ! $this->timestamp($candidate['reservation_expires_at'] ?? null)
            || ! $this->integer($candidate['total_cop'] ?? null, 0, intdiv(PHP_INT_MAX, 100))
            || ! is_array($candidate['payment_attempts'] ?? null)) {
            throw new AppsScriptCheckoutException(502);
        }
        $attempts = array_map(function (mixed $attempt): array {
            if (! is_array($attempt) || ! $this->text($attempt['wompi_transaction_id'] ?? null, 1, 200)
                || ! in_array($attempt['status'] ?? null, ['PENDING', 'APPROVED', 'DECLINED', 'VOIDED', 'ERROR'], true)
                || ! $this->integer($attempt['amount_in_cents'] ?? null, 1, PHP_INT_MAX)
                || ($attempt['currency'] ?? null) !== 'COP'
                || ! $this->timestamp($attempt['updated_at'] ?? null)) {
                throw new AppsScriptCheckoutException(502);
            }
            return $attempt;
        }, $candidate['payment_attempts']);

        return [...$candidate, 'payment_attempts' => $attempts];
    }

    private function normalizeReleasedReservation(mixed $result): array
    {
        if (! is_array($result) || ! $this->integer($result['order_id'] ?? null, 1, 2147483647)
            || ! $this->reference($result['reference'] ?? null) || ($result['reservation_status'] ?? null) !== 'RELEASED'
            || ! $this->integer($result['revision'] ?? null, 1, 2147483647)
            || ! is_bool($result['idempotency_replayed'] ?? null)) {
            throw new AppsScriptCheckoutException(502);
        }
        return $result;
    }

    private function normalizeReleaseResult(mixed $result): array
    {
        if (! is_array($result) || ! $this->reference($result['reference'] ?? null)
            || ! $this->text($result['reason'] ?? null, 1, 100)) {
            throw new AppsScriptCheckoutException(502);
        }
        return $result;
    }

    private function integer(mixed $value, int $min, int $max): bool
    {
        return is_int($value) && $value >= $min && $value <= $max;
    }

    private function text(mixed $value, int $min, int $max): bool
    {
        return is_string($value) && $value === trim($value) && strlen($value) >= $min && strlen($value) <= $max;
    }

    private function nullableText(mixed $value, int $max): bool
    {
        return $value === null || $this->text($value, 0, $max);
    }

    private function reference(mixed $value): bool
    {
        return $this->text($value, 1, 120)
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    private function timestamp(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $value) !== 1) {
            return false;
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d\\TH:i:s.v\\Z') === $value;
        } catch (\Throwable) {
            return false;
        }
    }

    private function nullableTimestamp(mixed $value): bool
    {
        return $value === null || $this->timestamp($value);
    }

    private function remoteStatus(?string $code): int
    {
        return match ($code) {
            'INVALID_REQUEST' => 400,
            'INSUFFICIENT_STOCK', 'PRODUCT_NOT_FOUND', 'PRODUCT_INACTIVE', 'IDEMPOTENCY_CONFLICT' => 409,
            'ORDER_NOT_FOUND' => 404,
            'AMOUNT_MISMATCH', 'CURRENCY_MISMATCH', 'TRANSACTION_CONFLICT' => 422,
            'LOCK_TIMEOUT' => 503,
            'NOT_FOUND' => 404,
            default => 502,
        };
    }

    private function isTimeout(ConnectionException $exception): bool
    {
        $messages = [];
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $messages[] = $current->getMessage();
        }

        $message = mb_strtolower(implode(' ', $messages));

        return str_contains($message, 'timeout') || str_contains($message, 'timed out') || str_contains($message, 'curl error 28');
    }
}
