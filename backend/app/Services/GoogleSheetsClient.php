<?php

namespace App\Services;

use App\Contracts\GoogleSheetsValuesClient;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Client;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\TransferStats;
use Illuminate\Support\Facades\Log;

final class GoogleSheetsClient implements GoogleSheetsValuesClient
{
    private const SCOPE = 'https://www.googleapis.com/auth/spreadsheets';

    private ?GuzzleClient $http = null;

    /**
     * An explicit identifier is only for isolated tooling (for example, a
     * laboratory spreadsheet). Runtime callers continue using configuration.
     */
    public function __construct(
        private readonly ?string $overrideSpreadsheetId = null
    ) {}

    public function httpClient(): GuzzleClient
    {
        if ($this->http) {
            return $this->http;
        }

        $credentials = config('services.google_sheets.credentials');
        $spreadsheet = $this->spreadsheetId();

        if (
            !is_string($credentials)
            || $credentials === ''
            || !is_file($credentials)
            || !is_string($spreadsheet)
            || $spreadsheet === ''
        ) {
            throw new ProductSheetsException(
                503,
                'GOOGLE_SHEETS_CONFIGURATION'
            );
        }

        try {
            $serviceCredentials = new ServiceAccountCredentials(
                self::SCOPE,
                $credentials
            );

            $serviceCredentials->useJwtAccessWithScope();

            $http = new GuzzleClient([
                'connect_timeout' => 10,
                'timeout' => 60,
                'verify' => true,
                'force_ip_resolve' => 'v4',
                'on_stats' => function (TransferStats $stats): void {
                    $response = $stats->hasResponse()
                        ? $stats->getResponse()
                        : null;

                    $s = $stats->getHandlerStats();

                    Log::debug('Google Sheets HTTP transfer stats.', [
                        'effective_uri' => $this->safeUri($stats),
                        'has_response' => $stats->hasResponse(),
                        'response_status' => $response?->getStatusCode(),
                        'transfer_time' => $stats->getTransferTime(),
                        'handler_error_data' => $stats->getHandlerErrorData(),
                        'handler_stats' => array_intersect_key(
                            $s,
                            array_flip([
                                'primary_ip',
                                'primary_port',
                                'local_ip',
                                'local_port',
                                'namelookup_time',
                                'connect_time',
                                'appconnect_time',
                                'pretransfer_time',
                                'starttransfer_time',
                                'total_time',
                                'http_version',
                                'ssl_verifyresult',
                                'num_connects',
                            ])
                        ),
                    ]);
                },
            ]);

            $google = new Client([
                'credentials' => $serviceCredentials,
                'scopes' => [self::SCOPE],
            ]);

            $google->setHttpClient($http);

            if ($google->getHttpClient() !== $http) {
                throw new \LogicException(
                    'Google Sheets HTTP client was not applied.'
                );
            }

            $this->http = $google->authorize();

            return $this->http;

        } catch (\Throwable $e) {
            throw new ProductSheetsException(
                503,
                'GOOGLE_SHEETS_CLIENT',
                $e
            );
        }
    }

    public function spreadsheetId(): string
    {
        $id = $this->overrideSpreadsheetId
            ?? config('services.google_sheets.spreadsheet_id');

        if (!is_string($id) || trim($id) === '') {
            throw new ProductSheetsException(
                503,
                'GOOGLE_SHEETS_CONFIGURATION'
            );
        }

        return trim($id);
    }

    /** @return list<list<mixed>> */
    public function getValues(string $range): array
    {
        $data = $this->valuesRequest(
            'GET',
            $this->valuesUrl($range),
            [],
            'read'
        );

        return is_array($data['values'] ?? null)
            ? $data['values']
            : [];
    }

    /**
     * @param list<string> $ranges
     * @return list<list<list<mixed>>>
     */
    public function batchGetValues(array $ranges): array
    {
        if (
            $ranges === []
            || array_filter(
                $ranges,
                fn ($range) => !is_string($range) || $range === ''
            )
        ) {
            throw new ProductSheetsException(
                422,
                'GOOGLE_SHEETS_READ'
            );
        }

        $url =
            'https://sheets.googleapis.com/v4/spreadsheets/'
            . rawurlencode($this->spreadsheetId())
            . '/values:batchGet';

        $query = implode(
            '&',
            array_map(
                static fn (string $range): string =>
                    'ranges=' . rawurlencode($range),
                $ranges
            )
        );

        $data = $this->valuesRequest(
            'GET',
            $url,
            ['query' => $query],
            'read'
        );

        if (
            !is_array($data['valueRanges'] ?? null)
            || count($data['valueRanges']) !== count($ranges)
        ) {
            throw new ProductSheetsException(
                502,
                'GOOGLE_SHEETS_INVALID_RESPONSE'
            );
        }

        return array_map(
            function (mixed $entry): array {
                if (
                    !is_array($entry)
                    || !is_array($entry['values'] ?? [])
                ) {
                    throw new ProductSheetsException(
                        502,
                        'GOOGLE_SHEETS_INVALID_RESPONSE'
                    );
                }

                return $entry['values'];
            },
            $data['valueRanges']
        );
    }

    /** @param list<list<mixed>> $values */
    public function updateValues(string $range, array $values): array
    {
        return $this->valuesRequest(
            'PUT',
            $this->valuesUrl($range),
            [
                'query' => [
                    'valueInputOption' => 'RAW',
                ],
                'json' => [
                    'values' => $values,
                ],
            ],
            'write'
        );
    }

    /** @param list<list<mixed>> $values */
    public function appendValues(string $range, array $values): array
    {
        return $this->valuesRequest(
            'POST',
            $this->valuesUrl($range) . ':append',
            [
                'query' => [
                    'valueInputOption' => 'RAW',
                    'insertDataOption' => 'INSERT_ROWS',
                ],
                'json' => [
                    'values' => $values,
                ],
            ],
            'write'
        );
    }

    /**
     * @param list<array{
     *     range:string,
     *     values:list<list<mixed>>
     * }> $data
     */
    public function batchUpdateValues(array $data): array
    {
        return $this->valuesRequest(
            'POST',
            'https://sheets.googleapis.com/v4/spreadsheets/'
            . rawurlencode($this->spreadsheetId())
            . '/values:batchUpdate',
            [
                'json' => [
                    'valueInputOption' => 'RAW',
                    'data' => $data,
                ],
            ],
            'write'
        );
    }

    private function valuesUrl(string $range): string
    {
        if ($range === '') {
            throw new ProductSheetsException(
                422,
                'GOOGLE_SHEETS_READ'
            );
        }

        return
            'https://sheets.googleapis.com/v4/spreadsheets/'
            . rawurlencode($this->spreadsheetId())
            . '/values/'
            . rawurlencode($range);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function valuesRequest(
        string $method,
        string $url,
        array $options,
        string $kind
    ): array {
        $maxAttempts = 4;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $this->httpClient()->request(
                    $method,
                    $url,
                    $options
                );

                $status = $response->getStatusCode();

                $data = json_decode(
                    (string) $response->getBody(),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                if (
                    $status < 200
                    || $status >= 300
                    || !is_array($data)
                ) {
                    throw new ProductSheetsException(
                        $status >= 400 ? $status : 502,
                        $kind === 'write'
                            ? 'GOOGLE_SHEETS_WRITE'
                            : 'GOOGLE_SHEETS_READ'
                    );
                }

                return $data;

            } catch (ProductSheetsException $e) {
                throw $e;

            } catch (\JsonException $e) {
                throw new ProductSheetsException(
                    502,
                    $kind === 'write'
                        ? 'GOOGLE_SHEETS_WRITE_INVALID_RESPONSE'
                        : 'GOOGLE_SHEETS_INVALID_RESPONSE',
                    $e
                );

            } catch (\Throwable $e) {
                $response = method_exists($e, 'getResponse')
                    ? $e->getResponse()
                    : null;

                $status = $response
                    ? $response->getStatusCode()
                    : 0;

                $retryable = $this->shouldRetryRequest(
                    $kind,
                    $status
                );

                if ($retryable && $attempt < $maxAttempts) {
                    $delayMs = match ($attempt) {
                        1 => 250,
                        2 => 500,
                        default => 1000,
                    };

                    Log::warning(
                        'Google Sheets request retry scheduled.',
                        [
                            'kind' => $kind,
                            'method' => $method,
                            'status' => $status,
                            'attempt' => $attempt,
                            'next_attempt' => $attempt + 1,
                            'delay_ms' => $delayMs,
                        ]
                    );

                    usleep($delayMs * 1000);

                    continue;
                }

                throw new ProductSheetsException(
                    $status >= 400 ? $status : 503,
                    $kind === 'write'
                        ? 'GOOGLE_SHEETS_WRITE_TRANSPORT'
                        : 'GOOGLE_SHEETS_TRANSPORT',
                    $e
                );
            }
        }

        throw new ProductSheetsException(
            503,
            $kind === 'write'
                ? 'GOOGLE_SHEETS_WRITE_TRANSPORT'
                : 'GOOGLE_SHEETS_TRANSPORT'
        );
    }

    private function shouldRetryRequest(
        string $kind,
        int $status
    ): bool {
        /*
         * Google rejected the request before processing it because of
         * temporary quota/rate limiting.
         */
        if ($status === 429) {
            return true;
        }

        /*
         * Reads are idempotent, so transient server errors may be retried
         * safely.
         */
        if (
            $kind === 'read'
            && in_array(
                $status,
                [500, 502, 503, 504],
                true
            )
        ) {
            return true;
        }

        /*
         * Do not retry ambiguous writes automatically. Google may have
         * applied the write even when the response was lost or failed.
         */
        return false;
    }

    private function safeUri(TransferStats $stats): string
    {
        $u = $stats->getEffectiveUri();

        $path = preg_replace(
            '#(/spreadsheets/)[^/]+#',
            '$1[redacted]',
            $u->getPath()
        ) ?: '/';

        return
            $u->getScheme()
            . '://'
            . $u->getHost()
            . $path;
    }
}