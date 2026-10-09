<?php

use App\Exceptions\CheckoutReservationPlanningException;
use Tests\Support\SqlCheckoutConcurrencySupport;

require dirname(__DIR__, 2).'/vendor/autoload.php';
try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $database = config('database.connections.mysql.database');
    $input = json_decode((string) file_get_contents($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($input) || !is_string($database) || !str_ends_with($database, '_test')) {
        fwrite(STDOUT, json_encode(['ok' => false, 'error' => 'UNSAFE_TEST_DATABASE', 'database' => $database, 'backend' => 'sql', 'phase' => 'bootstrap']).PHP_EOL);
        exit(2);
    }
    \Illuminate\Support\Facades\DB::connection('mysql')->getPdo();
    $tables = ['products', 'coupons', 'orders', 'order_items', 'order_coupons', 'coupon_reservations', 'checkout_reservations', 'checkout_reservation_items', 'payment_attempts'];
    foreach ($tables as $table) if (!\Illuminate\Support\Facades\Schema::connection('mysql')->hasTable($table)) throw new RuntimeException('Missing table: '.$table);
    file_put_contents($input['ready'], 'ready');
    $deadline = microtime(true) + 15;
    while (!is_file($input['barrier']) && microtime(true) < $deadline) usleep(10_000);
    if (!is_file($input['barrier'])) { fwrite(STDOUT, json_encode(['ok' => false, 'error' => 'BARRIER_TIMEOUT', 'database' => $database, 'backend' => 'sql', 'phase' => 'barrier']).PHP_EOL); exit(3); }
    $service = SqlCheckoutConcurrencySupport::service();
    $action = $input['action'] ?? 'prepare';
    if ($action === 'prepare') {
        $result = $service->prepare(['customer' => $input['customer'], 'items' => $input['items'], 'coupon_code' => $input['coupon_code'] ?? null], $input['key']);
    } elseif ($action === 'approved') {
        $result = $service->recordPaymentEvent(['transaction' => $input['event']]);
    } elseif ($action === 'release') {
        $result = $service->release($input['reference'], $input['revision'], $input['verified'] ?? []);
    } else {
        throw new RuntimeException('Unknown worker action: '.$action);
    }
    fwrite(STDOUT, json_encode(['ok' => $result['ok'] ?? true, 'database' => $database, 'backend' => 'sql', 'phase' => $action, 'result' => $result, 'error' => $result['error']['code'] ?? null], JSON_THROW_ON_ERROR).PHP_EOL);
} catch (CheckoutReservationPlanningException $exception) {
    fwrite(STDOUT, json_encode(['ok' => false, 'database' => $database ?? null, 'backend' => isset($app) ? 'sql' : null, 'phase' => 'prepare', 'error' => $exception->checkoutCode()], JSON_THROW_ON_ERROR).PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDOUT, json_encode(['ok' => false, 'database' => $database ?? null, 'backend' => isset($app) ? 'sql' : null, 'phase' => 'bootstrap_or_prepare', 'error' => 'UNEXPECTED', 'class' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR).PHP_EOL);
}
