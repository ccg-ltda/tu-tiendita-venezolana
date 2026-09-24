<?php

namespace App\Console\Commands;

use App\Services\AdminOrdersCache;
use App\Services\AppsScriptCheckoutClient;
use App\Services\AppsScriptCheckoutException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshAdminOrdersCache extends Command
{
    protected $signature = 'orders:refresh-admin-cache {--page=1} {--per-page=25}';

    protected $description = 'Refreshes one encrypted private administrator order-list cache page.';

    public function handle(AppsScriptCheckoutClient $client, AdminOrdersCache $cache): int
    {
        $page = filter_var($this->option('page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
        $perPage = filter_var($this->option('per-page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($page === false || $perPage === false) {
            $this->error('Invalid page or per-page option.');

            return self::FAILURE;
        }

        Log::info('admin_orders_scheduler_started', ['page' => $page, 'per_page' => $perPage]);
        try {
            $result = $client->adminListOrders($page, $perPage);
            $cache->putList($page, $perPage, $result);
        } catch (AppsScriptCheckoutException $exception) {
            Log::warning('Admin orders cache refresh failed.', ['page' => $page, 'per_page' => $perPage, 'status' => $exception->status()]);
            $this->error('Admin orders cache refresh failed.');

            return self::FAILURE;
        } catch (\Throwable) {
            Log::error('Admin orders cache refresh failed.', ['page' => $page, 'per_page' => $perPage]);
            $this->error('Admin orders cache refresh failed.');

            return self::FAILURE;
        }

        Log::info('admin_orders_cache_refreshed', ['page' => $page, 'per_page' => $perPage, 'order_count' => count($result['orders'])]);
        Log::info('admin_orders_scheduler_completed', ['page' => $page, 'per_page' => $perPage]);
        $this->info('Admin orders cache refreshed.');

        return self::SUCCESS;
    }
}
