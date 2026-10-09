<?php

namespace Tests\Feature\Checkout;

use App\Models\CheckoutReservation;
use App\Models\CheckoutReservationItem;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\CouponReservation;
use App\Models\Order;
use App\Models\OrderCoupon;
use App\Models\OrderItem;
use App\Models\NotificationOutbox;
use App\Models\Product;
use App\Models\Subcategory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Support\SqlCheckoutConcurrencySupport;

#[Group('mysql')]
final class MySqlCheckoutConcurrencyTest extends TestCase
{
    private string $tag;
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSafeDatabase();
        Artisan::call('migrate', ['--database' => 'mysql', '--force' => true]);
        $this->tag = 'sql-concurrency-'.bin2hex(random_bytes(8));
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.$this->tag;
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->assertSafeDatabase();
        $orderIds = Order::query()->where('customer_name', 'like', $this->tag.'%')->pluck('id');
        if ($orderIds->isNotEmpty()) {
            NotificationOutbox::query()->whereIn('order_id', $orderIds)->delete();
            CouponReservation::query()->whereIn('order_id', $orderIds)->delete();
            OrderCoupon::query()->whereIn('order_id', $orderIds)->delete();
            CheckoutReservationItem::query()->whereIn('checkout_reservation_id', CheckoutReservation::query()->whereIn('order_id', $orderIds)->pluck('id'))->delete();
            CheckoutReservation::query()->whereIn('order_id', $orderIds)->delete();
            OrderItem::query()->whereIn('order_id', $orderIds)->delete();
            DB::connection('mysql')->table('payment_attempts')->whereIn('order_id', $orderIds)->delete();
            Order::query()->whereIn('id', $orderIds)->delete();
        }
        Product::query()->where('name', 'like', $this->tag.'%')->delete();
        Subcategory::query()->where('name', 'like', $this->tag.'%')->delete();
        Category::query()->where('name', 'like', $this->tag.'%')->delete();
        Coupon::query()->where('code', 'like', strtoupper($this->tag).'%')->delete();
        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) @unlink($file);
        @rmdir($this->directory);
        parent::tearDown();
    }

    public function test_last_unit_is_reserved_once_without_overselling(): void
    {
        $id = $this->product(1); $results = $this->concurrently([[$id, 1], [$id, 1]]);
        $this->assertSame(1, count(array_filter($results, fn (array $r): bool => $r['ok'])), $this->workerDiagnostic($results));
        $this->assertSame(1, count(array_filter($results, fn (array $r): bool => ($r['error'] ?? null) === 'INSUFFICIENT_STOCK')));
        $this->assertSame(0, Product::findOrFail($id)->inventory);
        $this->assertSame(1, Order::query()->where('customer_name', 'like', $this->tag.'%')->count());
    }

    public function test_limited_stock_of_two_cannot_be_sold_twice(): void
    {
        $id = $this->product(2); $results = $this->concurrently([[$id, 2], [$id, 2]]);
        $this->assertSame(1, count(array_filter($results, fn (array $r): bool => $r['ok'])), $this->workerDiagnostic($results));
        $this->assertSame(0, Product::findOrFail($id)->inventory);
        $this->assertGreaterThanOrEqual(0, Product::findOrFail($id)->inventory);
    }

    public function test_reversed_payloads_complete_without_lock_order_deadlock(): void
    {
        $first = $this->product(2); $second = $this->product(2);
        $results = $this->concurrently([[$first, 1, $second, 1], [$second, 1, $first, 1]]);
        $this->assertSame(2, count(array_filter($results, fn (array $r): bool => $r['ok'])), $this->workerDiagnostic($results));
        $this->assertSame(0, Product::findOrFail($first)->inventory);
        $this->assertSame(0, Product::findOrFail($second)->inventory);
        $this->assertNotContains('UNEXPECTED', array_column($results, 'error'));
    }

    public function test_same_idempotency_key_creates_one_order_and_one_stock_decrement(): void
    {
        $id = $this->product(1); $key = (string) \Illuminate\Support\Str::uuid();
        $results = $this->concurrently([[$id, 1], [$id, 1]], $key);
        $successes = array_values(array_filter($results, fn (array $r): bool => $r['ok']));
        $this->assertCount(2, $successes, $this->workerDiagnostic($results));
        $this->assertSame($successes[0]['result']['order_id'], $successes[1]['result']['order_id']);
        $this->assertSame(1, Order::query()->where('customer_name', 'like', $this->tag.'%')->count(), $this->workerDiagnostic($results));
        $this->assertSame(0, Product::findOrFail($id)->inventory);
    }

    public function test_same_key_with_different_payload_never_creates_two_orders(): void
    {
        $id = $this->product(3); $key = (string) \Illuminate\Support\Str::uuid();
        $results = $this->concurrently([[$id, 1], [$id, 2]], $key);
        $this->assertSame(1, Order::query()->where('customer_name', 'like', $this->tag.'%')->count());
        $this->assertContains('IDEMPOTENCY_CONFLICT', array_column($results, 'error'));
        $this->assertGreaterThanOrEqual(0, Product::findOrFail($id)->inventory);
    }

    public function test_last_coupon_use_is_reserved_once(): void
    {
        $id = $this->product(2); $coupon = $this->coupon(1);
        $results = $this->concurrently([[$id, 1], [$id, 1]], null, $coupon->code);
        $this->assertSame(1, count(array_filter($results, fn (array $result): bool => $result['ok'])), $this->workerDiagnostic($results));
        $this->assertContains('COUPON_EXHAUSTED', array_column($results, 'error'));
        $this->assertSame(1, CouponReservation::query()->where('coupon_id', $coupon->id)->where('state', 'RESERVED')->count());
        $this->assertSame(0, $coupon->fresh()->used_count);
        $this->assertSame(1, Order::query()->where('customer_name', 'like', $this->tag.'%')->count());
        $this->assertSame(1, Product::findOrFail($id)->inventory);
    }

    public function test_concurrent_approved_consumes_coupon_once(): void
    {
        $id = $this->product(2); $coupon = $this->coupon(1);
        $prepared = $this->concurrently([[$id, 1]], null, $coupon->code)[0]['result'];
        $event = $this->paymentEvent($prepared, 'APPROVED');
        $results = $this->concurrentlyActions([['action' => 'approved', 'event' => $event], ['action' => 'approved', 'event' => $event]]);
        $this->assertCount(2, array_filter($results, fn (array $result): bool => $result['ok']), $this->workerDiagnostic($results));
        $this->assertSame('CONSUMED', CouponReservation::query()->where('order_id', $prepared['order_id'])->firstOrFail()->state);
        $this->assertSame(1, $coupon->fresh()->used_count);
        $this->assertSame(1, Product::findOrFail($id)->inventory);
    }

    public function test_approved_and_expiry_cannot_apply_both_coupon_effects(): void
    {
        $id = $this->product(1); $coupon = $this->coupon(1);
        $prepared = $this->concurrently([[$id, 1]], null, $coupon->code)[0]['result'];
        $reservation = CheckoutReservation::query()->where('order_id', $prepared['order_id'])->firstOrFail();
        $reservation->expires_at = now('UTC')->subMinute(); $reservation->save();
        $event = $this->paymentEvent($prepared, 'APPROVED');
        $this->concurrentlyActions([
            ['action' => 'approved', 'event' => $event],
            ['action' => 'release', 'reference' => $prepared['reference'], 'revision' => 1, 'verified' => []],
        ]);
        $couponReservation = CouponReservation::query()->where('order_id', $prepared['order_id'])->firstOrFail();
        $state = CheckoutReservation::query()->where('order_id', $prepared['order_id'])->firstOrFail()->state;
        $this->assertContains($couponReservation->state, ['CONSUMED', 'RELEASED']);
        $this->assertSame($couponReservation->state === 'CONSUMED' ? 'CONSUMED' : 'RELEASED', $state);
        $this->assertSame($couponReservation->state === 'CONSUMED' ? 1 : 0, $coupon->fresh()->used_count);
        $this->assertSame($couponReservation->state === 'CONSUMED' ? 0 : 1, Product::findOrFail($id)->inventory);
    }

    public function test_prepared_order_reserves_stock_without_enqueuing_order_created_notifications(): void
    {
        $id = $this->product(1);
        $prepared = $this->concurrently([[$id, 1]])[0]['result'];
        $this->assertSame('ACTIVE', CheckoutReservation::query()->where('order_id', $prepared['order_id'])->firstOrFail()->state);
        $this->assertSame(0, NotificationOutbox::query()->where('order_id', $prepared['order_id'])->where('notification_type', 'PEDIDO_CREADO')->count());
        $this->assertSame('AWAITING_PAYMENT', SqlCheckoutConcurrencySupport::service()->adminOrder($prepared['order_id'])['payment_flow_status']);
    }

    public function test_inactive_category_or_subcategory_cannot_be_reserved(): void
    {
        $category = Category::query()->create(['name' => $this->tag.' category', 'slug' => $this->tag.'-category', 'active' => false, 'sort_order' => 1]);
        $subcategory = Subcategory::query()->create(['category_id' => $category->id, 'name' => $this->tag.' subcategory', 'slug' => $this->tag.'-subcategory', 'active' => true, 'sort_order' => 1]);
        $categoryProductId = $this->product(1);
        Product::query()->whereKey($categoryProductId)->update(['category_id' => $category->id, 'subcategory_id' => $subcategory->id]);

        $categoryResult = $this->concurrently([[$categoryProductId, 1]])[0];
        $this->assertSame('PRODUCT_INACTIVE', $categoryResult['error']);
        $this->assertSame(1, Product::findOrFail($categoryProductId)->inventory);

        $category->update(['active' => true]);
        $subcategory->update(['active' => false]);
        $subcategoryProductId = $this->product(1);
        Product::query()->whereKey($subcategoryProductId)->update(['category_id' => $category->id, 'subcategory_id' => $subcategory->id]);

        $subcategoryResult = $this->concurrently([[$subcategoryProductId, 1]])[0];
        $this->assertSame('PRODUCT_INACTIVE', $subcategoryResult['error']);
        $this->assertSame(1, Product::findOrFail($subcategoryProductId)->inventory);
    }

    public function test_wompi_prepare_endpoint_uses_the_container_wired_sql_checkout_service(): void
    {
        config([
            'services.wompi.public_key' => 'pub_test_public_key',
            'services.wompi.integrity_secret' => 'integrity_test_secret',
        ]);
        RateLimiter::clear('wompi-prepare:127.0.0.1');
        $productId = $this->product(1);
        $key = (string) \Illuminate\Support\Str::uuid();

        $response = $this->withHeader('Idempotency-Key', $key)->postJson('/api/payments/wompi/prepare', [
            'customer' => $this->customer(),
            'items' => [['id' => $productId, 'qty' => 1]],
        ])->assertCreated();

        $orderId = $response->json('order.id');
        $this->assertIsInt($orderId);
        $this->assertSame(100000, $response->json('payment.amountInCents'));
        $this->assertSame(0, NotificationOutbox::query()->where('order_id', $orderId)->where('notification_type', 'PEDIDO_CREADO')->count());
    }

    public function test_first_approved_payment_enqueues_customer_and_admin_order_created_notifications_once(): void
    {
        $id = $this->product(1);
        $prepared = $this->concurrently([[$id, 1]])[0]['result'];
        $service = SqlCheckoutConcurrencySupport::service();

        $service->recordPaymentEvent(['transaction' => $this->paymentEvent($prepared, 'APPROVED')]);
        $entries = NotificationOutbox::query()->where('order_id', $prepared['order_id'])->where('notification_type', 'PEDIDO_CREADO')->orderBy('recipient_kind')->get();

        $this->assertSame('APPROVED', Order::findOrFail($prepared['order_id'])->payment_status);
        $this->assertSame('OPERATIONAL', $service->adminOrder($prepared['order_id'])['payment_flow_status']);
        $this->assertCount(2, $entries);
        $this->assertSame(['admin', 'customer'], $entries->pluck('recipient_kind')->all());
    }

    public function test_replayed_approved_payment_does_not_duplicate_order_created_notifications(): void
    {
        $id = $this->product(1);
        $prepared = $this->concurrently([[$id, 1]])[0]['result'];
        $event = $this->paymentEvent($prepared, 'APPROVED');
        $service = SqlCheckoutConcurrencySupport::service();

        $service->recordPaymentEvent(['transaction' => $event]);
        $service->recordPaymentEvent(['transaction' => $event]);

        $this->assertSame(2, NotificationOutbox::query()->where('order_id', $prepared['order_id'])->where('notification_type', 'PEDIDO_CREADO')->count());
    }

    public function test_non_approved_payment_statuses_do_not_enqueue_order_created_notifications(): void
    {
        $service = SqlCheckoutConcurrencySupport::service();
        foreach (['PENDING', 'DECLINED', 'VOIDED', 'ERROR'] as $status) {
            $id = $this->product(1);
            $prepared = $this->concurrently([[$id, 1]])[0]['result'];
            $service->recordPaymentEvent(['transaction' => $this->paymentEvent($prepared, $status)]);

            $this->assertSame('PENDING', Order::findOrFail($prepared['order_id'])->payment_status, $status);
            $this->assertSame(0, NotificationOutbox::query()->where('order_id', $prepared['order_id'])->where('notification_type', 'PEDIDO_CREADO')->count(), $status);
        }
    }

    public function test_expired_unpaid_reservation_releases_stock_without_order_created_notifications(): void
    {
        $id = $this->product(1);
        $prepared = $this->concurrently([[$id, 1]])[0]['result'];
        $reservation = CheckoutReservation::query()->where('order_id', $prepared['order_id'])->firstOrFail();
        $reservation->expires_at = now('UTC')->subMinute();
        $reservation->save();

        SqlCheckoutConcurrencySupport::service()->release($prepared['reference'], $prepared['revision'], []);

        $this->assertSame('RELEASED', $reservation->fresh()->state);
        $this->assertSame(1, Product::findOrFail($id)->inventory);
        $this->assertSame(0, NotificationOutbox::query()->where('order_id', $prepared['order_id'])->where('notification_type', 'PEDIDO_CREADO')->count());
        $this->assertSame('PAYMENT_NOT_COMPLETED', SqlCheckoutConcurrencySupport::service()->adminOrder($prepared['order_id'])['payment_flow_status']);
    }

    public function test_operational_status_creates_customer_outbox_entry_once(): void
    {
        $id = $this->product(1);
        $prepared = $this->concurrently([[$id, 1]])[0]['result'];
        $service = SqlCheckoutConcurrencySupport::service();
        $service->recordPaymentEvent(['transaction' => $this->paymentEvent($prepared, 'APPROVED')]);
        $this->assertTrue($service->updateAdminStatus($prepared['order_id'], 'PROCESSING')['ok']);
        $service->updateAdminStatus($prepared['order_id'], 'PROCESSING');
        $this->assertSame(1, NotificationOutbox::query()->where('order_id', $prepared['order_id'])->where('notification_type', 'EN_PREPARACION')->count());
    }

    private function product(int $inventory): int
    {
        $id = random_int(100000000, 900000000);
        Product::query()->create(['id' => $id, 'category' => 'Test', 'subcategory' => 'Concurrency', 'name' => $this->tag.'-'.$id, 'presentation' => 'Unit', 'price_cop' => 1000, 'inventory' => $inventory, 'active' => true, 'revision' => 1]);
        return $id;
    }

    private function coupon(int $maxUses): Coupon
    {
        return Coupon::query()->create(['code' => strtoupper($this->tag).'-ONE', 'description' => null, 'active' => true, 'discount_type' => 'percent', 'discount_value' => 10, 'max_uses' => $maxUses, 'used_count' => 0, 'revision' => 1]);
    }

    /** @param list<list<int>> $requests @return list<array<string,mixed>> */
    private function concurrently(array $requests, ?string $key = null, ?string $couponCode = null): array
    {
        $barrier = $this->directory.DIRECTORY_SEPARATOR.'go'; $processes = [];
        foreach ($requests as $index => $request) {
            $items = []; for ($i = 0; $i < count($request); $i += 2) $items[] = ['id' => $request[$i], 'qty' => $request[$i + 1]];
            $input = $this->directory.DIRECTORY_SEPARATOR.$index.'.json'; $ready = $this->directory.DIRECTORY_SEPARATOR.$index.'.ready';
            file_put_contents($input, json_encode(['action' => 'prepare', 'items' => $items, 'coupon_code' => $couponCode, 'key' => $key ?? (string) \Illuminate\Support\Str::uuid(), 'ready' => $ready, 'barrier' => $barrier, 'customer' => $this->customer()], JSON_THROW_ON_ERROR));
            $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('tests/Support/sql_checkout_concurrency_worker.php')).' '.escapeshellarg($input);
            $pipes = []; $processes[] = ['process' => proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path(), $this->workerEnvironment()), 'pipes' => $pipes, 'ready' => $ready];
        }
        $deadline = microtime(true) + 20; while (count(array_filter($processes, fn (array $p): bool => is_file($p['ready']))) !== count($processes) && microtime(true) < $deadline) usleep(10_000);
        $this->assertSame(count($processes), count(array_filter($processes, fn (array $p): bool => is_file($p['ready']))), 'Workers did not reach the concurrency barrier.');
        touch($barrier); $results = [];
        foreach ($processes as $index => $entry) {
            fclose($entry['pipes'][0]); $stdout = stream_get_contents($entry['pipes'][1]); $stderr = stream_get_contents($entry['pipes'][2]); fclose($entry['pipes'][1]); fclose($entry['pipes'][2]); $exitCode = proc_close($entry['process']);
            try { $result = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR); }
            catch (\Throwable $exception) { $this->fail("Worker {$index} emitted invalid JSON; exit={$exitCode}; stderr=".trim($stderr).'; stdout='.trim($stdout)); }
            $this->assertSame('', trim($stderr), "Worker {$index} stderr; exit={$exitCode}; payload=".json_encode($result));
            $this->assertIsArray($result, "Worker {$index} payload; exit={$exitCode}; stdout=".trim($stdout));
            if (in_array($result['error'] ?? null, ['UNSAFE_TEST_DATABASE', 'BARRIER_TIMEOUT', 'UNEXPECTED'], true)) $this->fail("Worker {$index} failed before a controlled checkout result; exit={$exitCode}; payload=".json_encode($result).'; stderr='.trim($stderr));
            $result['_exit_code'] = $exitCode;
            $result['_stderr'] = trim($stderr);
            $results[] = $result;
        }
        return $results;
    }

    /** @param list<array<string,mixed>> $actions @return list<array<string,mixed>> */
    private function concurrentlyActions(array $actions): array
    {
        $barrier = $this->directory.DIRECTORY_SEPARATOR.'actions-go'; $processes = [];
        foreach ($actions as $index => $action) {
            $input = $this->directory.DIRECTORY_SEPARATOR.'action-'.$index.'.json'; $ready = $this->directory.DIRECTORY_SEPARATOR.'action-'.$index.'.ready';
            file_put_contents($input, json_encode($action + ['ready' => $ready, 'barrier' => $barrier], JSON_THROW_ON_ERROR));
            $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('tests/Support/sql_checkout_concurrency_worker.php')).' '.escapeshellarg($input);
            $pipes = []; $processes[] = ['process' => proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path(), $this->workerEnvironment()), 'pipes' => $pipes, 'ready' => $ready];
        }
        $deadline = microtime(true) + 20; while (count(array_filter($processes, fn (array $process): bool => is_file($process['ready']))) !== count($processes) && microtime(true) < $deadline) usleep(10_000);
        $this->assertSame(count($processes), count(array_filter($processes, fn (array $process): bool => is_file($process['ready']))), 'Workers did not reach the concurrency barrier.');
        touch($barrier); $results = [];
        foreach ($processes as $index => $entry) {
            fclose($entry['pipes'][0]); $stdout = stream_get_contents($entry['pipes'][1]); $stderr = stream_get_contents($entry['pipes'][2]); fclose($entry['pipes'][1]); fclose($entry['pipes'][2]); $exitCode = proc_close($entry['process']);
            try { $result = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR); } catch (\Throwable) { $this->fail("Worker {$index} emitted invalid JSON; exit={$exitCode}; stderr=".trim($stderr).'; stdout='.trim($stdout)); }
            $this->assertSame('', trim($stderr), "Worker {$index} stderr; exit={$exitCode}; payload=".json_encode($result));
            if (in_array($result['error'] ?? null, ['UNSAFE_TEST_DATABASE', 'BARRIER_TIMEOUT', 'UNEXPECTED'], true)) $this->fail("Worker {$index} failed; exit={$exitCode}; payload=".json_encode($result).'; stderr='.trim($stderr));
            $result['_exit_code'] = $exitCode; $result['_stderr'] = trim($stderr); $results[] = $result;
        }
        return $results;
    }

    /** @return array<string,string> */
    private function workerEnvironment(): array
    {
        $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => (string) config('database.connections.mysql.host'), 'DB_PORT' => (string) config('database.connections.mysql.port'), 'DB_DATABASE' => (string) config('database.connections.mysql.database'), 'DB_USERNAME' => (string) config('database.connections.mysql.username'), 'DB_PASSWORD' => (string) config('database.connections.mysql.password'), 'CHECKOUT_WRITER_BACKEND' => 'sql', 'CACHE_STORE' => 'file'];
        foreach (['PATH', 'SystemRoot', 'WINDIR', 'COMSPEC', 'TEMP', 'TMP'] as $name) if (($value = getenv($name)) !== false) $environment[$name] = $value;
        return $environment;
    }

    private function customer(): array { return ['name' => $this->tag.' customer', 'email' => 'concurrency@example.test', 'phone' => '+573000000000', 'document' => '123456', 'address' => 'Calle 1', 'extra' => null, 'city' => 'Bogota', 'region' => 'Bogota', 'postal' => null]; }
    private function paymentEvent(array $prepared, string $status): array { return ['id' => 'coupon-concurrency-'.$prepared['order_id'], 'reference' => $prepared['reference'], 'status' => $status, 'payment_method' => 'CARD', 'amount_in_cents' => $prepared['total_cop'] * 100, 'currency' => 'COP', 'event_occurred_at' => now('UTC')->format('Y-m-d\\TH:i:s.v\\Z')]; }
    private function assertSafeDatabase(): void { $database = config('database.connections.mysql.database'); if (!is_string($database) || !str_ends_with($database, '_test')) throw new \RuntimeException('MySQL concurrency tests require a database ending in _test.'); }
    /** @param list<array<string,mixed>> $results */
    private function workerDiagnostic(array $results): string { return 'workers='.json_encode($results, JSON_UNESCAPED_SLASHES); }
}
