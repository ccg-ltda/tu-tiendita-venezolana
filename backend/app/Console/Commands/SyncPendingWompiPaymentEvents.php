<?php

namespace App\Console\Commands;

use App\Services\CheckoutWriterGateway;
use App\Services\CheckoutGatewayException;
use App\Services\WompiPaymentEventOutboxStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncPendingWompiPaymentEvents extends Command
{
    protected $signature = 'wompi:sync-pending-payment-events';

    protected $description = 'Persists pending Wompi payment events in checkout storage.';

    public function handle(WompiPaymentEventOutboxStore $outbox, CheckoutWriterGateway $checkout): int
    {
        $summary = ['processed' => 0, 'synced' => 0, 'pending' => 0];
        foreach ($outbox->all() as $event) {
            ++$summary['processed'];
            try {
                $checkout->recordPaymentEvent($event['transaction']);
                $outbox->remove($event['transaction']['id']);
                ++$summary['synced'];
            } catch (CheckoutGatewayException $exception) {
                $outbox->markFailed($event);
                ++$summary['pending'];
                Log::warning('Pending Wompi payment event was not persisted.', ['transaction_id' => $event['transaction']['id'], 'status' => $exception->status(), 'remote_code' => $exception->remoteCode()]);
            } catch (\Throwable) {
                $outbox->markFailed($event);
                ++$summary['pending'];
                Log::error('Pending Wompi payment event could not be synchronized.', ['transaction_id' => $event['transaction']['id']]);
            }
        }

        $this->info("processed={$summary['processed']} synced={$summary['synced']} pending={$summary['pending']}");

        return $summary['pending'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
