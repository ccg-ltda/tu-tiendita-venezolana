<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('wompi:release-expired-reservations')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('wompi:sync-pending-payment-events')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('orders:send-pending-notifications')
    ->everyMinute()
    ->withoutOverlapping();
