<?php

namespace App\Console\Commands;

use App\Services\AdminOrdersCache;
use App\Services\AppsScriptCheckoutClient;
use App\Services\AppsScriptCheckoutException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshAdminOrderDetailCache extends Command
{
    protected $signature = 'orders:refresh-admin-detail {orderId}';

    protected $description = 'Refreshes one encrypted private administrator order-detail cache entry.';

    public function handle(AppsScriptCheckoutClient $client, AdminOrdersCache $cache): int
    {
        $orderId = filter_var($this->argument('orderId'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
        if ($orderId === false) {
            $this->error('Invalid order identifier.');

            return self::FAILURE;
        }

        try {
            $detail = $client->adminGetOrder($orderId);
            $cache->putDetail($orderId, $detail);
        } catch (AppsScriptCheckoutException $exception) {
            Log::warning('Admin order detail cache refresh failed.', ['order_id' => $orderId, 'status' => $exception->status()]);
            $this->error('Admin order detail cache refresh failed.');

            return self::FAILURE;
        } catch (\Throwable) {
            Log::error('Admin order detail cache refresh failed.', ['order_id' => $orderId]);
            $this->error('Admin order detail cache refresh failed.');

            return self::FAILURE;
        }

        Log::info('Admin order detail cache refreshed.', ['order_id' => $orderId]);
        $this->info('Admin order detail cache refreshed.');

        return self::SUCCESS;
    }
}
