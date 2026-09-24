<?php

use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('wompi:release-expired-reservations')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('products:refresh-catalog')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('products:sync-pending')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('orders:refresh-admin-cache --page=1 --per-page=25')
    ->everyMinute()
    ->withoutOverlapping(5);

Artisan::command('apps-script:test-connection', function (): int {
    $url = config('services.apps_script.url');
    $apiKey = config('services.apps_script.api_key');

    if (! is_string($url) || $url === '' || ! is_string($apiKey) || $apiKey === '') {
        $this->error('Apps Script configuration is unavailable.');

        return Command::FAILURE;
    }

    try {
        $response = Http::acceptJson()
            ->asJson()
            ->connectTimeout(3)
            ->timeout(10)
            ->post($url, [
                'action' => 'list_products',
                'api_key' => $apiKey,
            ]);
    } catch (ConnectionException) {
        $this->error('Apps Script connection failed.');

        return Command::FAILURE;
    } catch (\Throwable) {
        $this->error('Apps Script request failed.');

        return Command::FAILURE;
    }

    $payload = $response->json();
    if (! $response->successful() || ! is_array($payload) || ($payload['ok'] ?? null) !== true || ! is_array($payload['data'] ?? null)) {
        $this->error('Apps Script returned an invalid response.');

        return Command::FAILURE;
    }

    $this->info('Laravel -> Apps Script -> Google Sheets is working.');
    $this->line('Products received: '.count($payload['data']));

    return Command::SUCCESS;
})->purpose('Checks the Apps Script product connection.');
