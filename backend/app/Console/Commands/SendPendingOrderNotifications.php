<?php

namespace App\Console\Commands;

use App\Services\OrderNotificationDeliveryService;
use App\Services\OrderNotificationOutboxStore;
use Illuminate\Console\Command;

final class SendPendingOrderNotifications extends Command
{
    protected $signature = 'orders:send-pending-notifications';
    protected $description = 'Delivers pending order notification outbox entries.';

    public function handle(OrderNotificationOutboxStore $outbox, OrderNotificationDeliveryService $delivery): int
    {
        $sent = 0;
        $failed = 0;
        foreach ($outbox->all() as $entry) {
            try {
                if ($delivery->deliver($entry)) ++$sent;
                else ++$failed;
            } catch (\Throwable) {
                // A malformed entry or an unexpected dependency must not stop the batch.
                ++$failed;
            }
        }
        $pending = count($outbox->all());
        $this->info("sent={$sent} failed={$failed} pending={$pending}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
