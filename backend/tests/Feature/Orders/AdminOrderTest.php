<?php

namespace Tests\Feature\Orders;

use App\Contracts\GoogleSheetsValuesClient;
use App\Repositories\CheckoutSheetsRepository;
use App\Services\AdminOrdersCache;
use App\Services\AppsScriptCheckoutClient;
use App\Services\AppsScriptCheckoutException;
use App\Services\CheckoutAdminOrderStatusService;
use App\Services\CheckoutDirectPreparationService;
use App\Services\CheckoutExpiredReservationReleaseService;
use App\Services\CheckoutLock;
use App\Services\CheckoutPaymentEventService;
use App\Services\CheckoutReleaseCandidateReader;
use App\Services\CheckoutUtcTimestamp;
use App\Services\CheckoutWriterGateway;
use App\Services\OrderNotificationOutboxStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminOrderTest extends TestCase
{
    private AdminOrdersCache $cache;

    private string $testStoragePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testStoragePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ttv-admin-orders-'.bin2hex(random_bytes(8));
        app()->useStoragePath($this->testStoragePath);

        // This feature suite must never share the developer's file cache.
        config(['cache.default' => 'array']);
        app()->forgetInstance('cache');
        Cache::clearResolvedInstance('cache');
        Cache::flush();
        Http::fake();

        $this->cache = app(AdminOrdersCache::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testStoragePath);

        parent::tearDown();
    }

    public function test_unauthenticated_requests_are_rejected_without_upstream(): void
    {
        $this->getJson('/api/admin/orders')->assertUnauthorized();
        $this->getJson('/api/admin/orders/42')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_fresh_list_is_served_without_upstream(): void
    {
        $data = $this->listData();
        $this->cache->putList(1, 25, $data);
        $this->withSession(['admin_authenticated' => true])->getJson('/api/admin/orders')->assertOk()->assertExactJson($data);
        Http::assertNothingSent();
    }

    public function test_stale_list_is_served_without_upstream(): void
    {
        $data = $this->listData();
        $this->cache->putList(1, 25, $data);
        $this->travel(61)->seconds();
        $this->withSession(['admin_authenticated' => true])->getJson('/api/admin/orders')->assertOk()->assertExactJson($data);
        Http::assertNothingSent();
    }

    public function test_missing_list_returns_503_without_upstream(): void
    {
        $this->withSession(['admin_authenticated' => true])->getJson('/api/admin/orders')->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_fresh_detail_is_served_without_upstream(): void
    {
        $detail = $this->detail();
        $this->cache->putDetail(42, $detail);
        $this->withSession(['admin_authenticated' => true])->getJson('/api/admin/orders/42')->assertOk()->assertExactJson(['order' => $detail]);
        Http::assertNothingSent();
    }

    public function test_stale_detail_is_served_without_upstream(): void
    {
        $detail = $this->detail();
        $this->cache->putDetail(42, $detail);
        $this->travel(61)->seconds();
        $this->withSession(['admin_authenticated' => true])->getJson('/api/admin/orders/42')->assertOk()->assertExactJson(['order' => $detail]);
        Http::assertNothingSent();
    }

    public function test_missing_detail_is_loaded_once_and_then_served_from_cache(): void
    {
        $detail = $this->detail();
        $this->useAppsScriptAdministrativeGateway(new class($detail) extends AppsScriptCheckoutClient {
            public function __construct(private readonly array $detail) {}

            public function adminGetOrder(int $orderId): array
            {
                if ($orderId !== 42) throw new \LogicException('Unexpected order lookup.');

                return $this->detail;
            }
        });

        $this->withSession(['admin_authenticated' => true])->getJson('/api/admin/orders/42')->assertOk()->assertExactJson(['order' => $detail]);
        $this->assertSame($detail, $this->cache->detail(42)['data']);
        Http::assertNothingSent();

        Http::fake();
        $this->withSession(['admin_authenticated' => true])->getJson('/api/admin/orders/42')->assertOk()->assertExactJson(['order' => $detail]);
        Http::assertNothingSent();
    }

    public function test_detail_refresh_failure_does_not_break_the_cached_list(): void
    {
        $list = $this->listData();
        $this->cache->putList(1, 25, $list);
        $this->useAppsScriptAdministrativeGateway(new class extends AppsScriptCheckoutClient {
            public function adminGetOrder(int $orderId): array
            {
                throw new AppsScriptCheckoutException(503);
            }
        });

        $this->withSession(['admin_authenticated' => true])->getJson('/api/admin/orders/42')->assertStatus(503);
        $this->withSession(['admin_authenticated' => true])->getJson('/api/admin/orders')->assertOk()->assertExactJson($list);
        Http::assertNothingSent();
    }

    public function test_refresh_lock_returns_callback_result(): void
    {
        $this->assertSame(['ok' => true], $this->cache->withRefreshLock('test-list', static fn (): array => ['ok' => true]));
    }

    public function test_scheduler_refreshes_list_without_precaching_each_detail(): void
    {
        $this->useDirectListRepository([$this->orderRow('PENDING', 'APPROVED')]);

        $this->artisan('orders:refresh-admin-cache')->assertSuccessful();
        $this->assertNotNull($this->cache->list(1, 25));
        $this->assertNull($this->cache->detail(42));
        Http::assertNothingSent();
    }

    public function test_scheduler_failure_preserves_existing_cache(): void
    {
        $data = $this->listData();
        $this->cache->putList(1, 25, $data);
        $this->useFailingDirectListRepository();
        $this->artisan('orders:refresh-admin-cache')->assertFailed();
        $this->assertSame($data, $this->cache->list(1, 25)['data']);
    }

    #[DataProvider('statusTransitions')]
    public function test_operational_status_transitions_are_validated(string $from, string $payment, string $target, int $expectedStatus): void
    {
        $this->useDirectAdministrativeGateway($from, $payment);

        $this->withSession(['admin_authenticated' => true])->patchJson('/api/admin/orders/42/status', ['status' => $target])->assertStatus($expectedStatus);
        Http::assertNothingSent();
    }

    public static function statusTransitions(): array
    {
        return [
            'paid pending to processing' => ['PENDING', 'APPROVED', 'PROCESSING', 200],
            'unpaid pending to processing' => ['PENDING', 'PENDING', 'PROCESSING', 422],
            'processing to ready' => ['PROCESSING', 'APPROVED', 'READY', 200],
            'ready to shipped' => ['READY', 'APPROVED', 'SHIPPED', 200],
            'ready direct delivered' => ['READY', 'APPROVED', 'DELIVERED', 200],
            'shipped to delivered' => ['SHIPPED', 'APPROVED', 'DELIVERED', 200],
            'pending to shipped rejected' => ['PENDING', 'APPROVED', 'SHIPPED', 422],
            'delivered final' => ['DELIVERED', 'APPROVED', 'PROCESSING', 422],
            'cancelled final' => ['CANCELLED', 'APPROVED', 'PROCESSING', 422],
        ];
    }

    public function test_approved_order_cannot_be_cancelled_through_the_administrative_endpoint(): void
    {
        $this->useDirectAdministrativeGateway('PENDING', 'APPROVED');

        $this->withSession(['admin_authenticated' => true])
            ->patchJson('/api/admin/orders/42/status', ['status' => 'CANCELLED'])
            ->assertStatus(422)
            ->assertJson(['message' => 'Estado de pedido inválido.']);
        Http::assertNothingSent();
    }

    /** @param list<list<mixed>> $orders */
    private function useDirectListRepository(array $orders): void
    {
        $tables = ['Pedidos' => [[
            'order_id','reference','status','payment_status','reservation_status','reservation_expires_at','paid_at','payment_last_event_at','checkout_idempotency_key','checkout_payload_hash','release_id','release_fingerprint','released_at','customer_name','customer_email','customer_phone','customer_document','address','extra','city','region','postal','total_cop','created_at','updated_at','revision',
        ], ...$orders]];
        $client = new class($tables) implements GoogleSheetsValuesClient {
            public function __construct(public array $tables) {}
            public function getValues(string $range): array { return []; }
            public function batchGetValues(array $ranges): array { return array_map(fn (string $range): array => str_ends_with($range, '1:1') ? [$this->tables['Pedidos'][0]] : $this->tables['Pedidos'], $ranges); }
            public function updateValues(string $range, array $values): array { throw new \LogicException('Read only.'); }
            public function appendValues(string $range, array $values): array { throw new \LogicException('Read only.'); }
            public function batchUpdateValues(array $data): array { throw new \LogicException('Read only.'); }
        };
        app()->instance(CheckoutSheetsRepository::class, new CheckoutSheetsRepository($client));
    }

    private function useFailingDirectListRepository(): void
    {
        $client = new class implements GoogleSheetsValuesClient {
            public function getValues(string $range): array { throw new \RuntimeException('Direct Sheets read failed.'); }
            public function batchGetValues(array $ranges): array { throw new \RuntimeException('Direct Sheets read failed.'); }
            public function updateValues(string $range, array $values): array { throw new \LogicException('Read only.'); }
            public function appendValues(string $range, array $values): array { throw new \LogicException('Read only.'); }
            public function batchUpdateValues(array $data): array { throw new \LogicException('Read only.'); }
        };
        app()->instance(CheckoutSheetsRepository::class, new CheckoutSheetsRepository($client));
    }

    private function useDirectAdministrativeGateway(string $status, string $paymentStatus): void
    {
        config(['checkout.writer_backend' => 'direct']);

        $client = new class($this->orderRow($status, $paymentStatus)) implements GoogleSheetsValuesClient {
            /** @var array<string, list<list<mixed>>> */
            public array $tables;

            /** @param list<mixed> $order */
            public function __construct(array $order)
            {
                $this->tables = [
                    'Pedidos' => [
                        ['order_id','reference','status','payment_status','reservation_status','reservation_expires_at','paid_at','payment_last_event_at','checkout_idempotency_key','checkout_payload_hash','release_id','release_fingerprint','released_at','customer_name','customer_email','customer_phone','customer_document','address','extra','city','region','postal','total_cop','created_at','updated_at','revision'],
                        $order,
                    ],
                    'PedidoItems' => [
                        ['order_item_id','order_id','product_id','product_name','unit_price_cop','quantity','created_at'],
                        [9,42,193,'Producto historico',19500,1,'2026-09-21T13:04:20.000Z'],
                    ],
                    'Pagos' => [['payment_attempt_id','order_id','wompi_transaction_id','status','payment_method','amount_in_cents','currency','created_at','updated_at']],
                ];
            }

            public function getValues(string $range): array { return []; }

            public function batchGetValues(array $ranges): array
            {
                return array_map(function (string $range): array {
                    $sheet = explode('!', $range, 2)[0];

                    return str_ends_with($range, '1:1') ? [$this->tables[$sheet][0]] : $this->tables[$sheet];
                }, $ranges);
            }

            public function updateValues(string $range, array $values): array
            {
                preg_match('/^Pedidos!A(\d+):Z\d+$/', $range, $matches);
                if ($matches === []) throw new \LogicException('Unexpected direct Sheets write.');
                $this->tables['Pedidos'][(int) $matches[1] - 1] = $values[0];

                return [];
            }

            public function appendValues(string $range, array $values): array { throw new \LogicException('Unexpected append.'); }

            public function batchUpdateValues(array $data): array { throw new \LogicException('Unexpected batch update.'); }
        };

        $sheets = new CheckoutSheetsRepository($client);
        $admin = new CheckoutAdminOrderStatusService(new CheckoutLock, $sheets, new OrderNotificationOutboxStore($this->testStoragePath.'/direct-notifications.json'), new CheckoutUtcTimestamp);
        app()->instance(CheckoutWriterGateway::class, new CheckoutWriterGateway(
            app(AppsScriptCheckoutClient::class),
            app(CheckoutDirectPreparationService::class),
            app(CheckoutPaymentEventService::class),
            app(CheckoutReleaseCandidateReader::class),
            app(CheckoutExpiredReservationReleaseService::class),
            $admin,
            $sheets,
        ));
    }

    private function useAppsScriptAdministrativeGateway(AppsScriptCheckoutClient $client): void
    {
        $this->useAppsScriptClient($client);
        app()->instance(CheckoutWriterGateway::class, new CheckoutWriterGateway(
            $client,
            app(CheckoutDirectPreparationService::class),
            app(CheckoutPaymentEventService::class),
            app(CheckoutReleaseCandidateReader::class),
            app(CheckoutExpiredReservationReleaseService::class),
            app(CheckoutAdminOrderStatusService::class),
            app(CheckoutSheetsRepository::class),
        ));
    }

    private function useAppsScriptClient(AppsScriptCheckoutClient $client): void
    {
        config(['checkout.writer_backend' => 'apps_script']);
        app()->instance(AppsScriptCheckoutClient::class, $client);
    }

    /** @return list<mixed> */
    private function orderRow(string $status, string $paymentStatus): array
    {
        $approved = $paymentStatus === 'APPROVED';

        return [42,'TTV-ADMIN-42',$status,$paymentStatus,$approved ? 'CONSUMED' : 'ACTIVE','2026-09-21T14:04:20.000Z',$approved ? '2026-09-21T13:04:20.000Z' : '',$approved ? '2026-09-21T13:04:20.000Z' : '','11111111-1111-4111-8111-111111111111',str_repeat('a',64),'','','','Cliente de Prueba','cliente@example.test','3000000000','1000000000','Direccion de prueba','','Bogota','Bogota D.C.','',19500,'2026-09-21T13:04:20.000Z','2026-09-21T13:04:20.000Z',1];
    }

    private function listData(): array
    {
        return ['orders' => [['id' => 42, 'reference' => 'TTV-ADMIN-42', 'status' => 'PENDING', 'payment_status' => 'APPROVED', 'customer_name' => 'Cliente de Prueba', 'total' => 19500, 'created_at' => '2026-09-21T13:04:20.000Z']], 'pagination' => ['current_page' => 1, 'per_page' => 25, 'total' => 1, 'last_page' => 1]];
    }

    private function detail(int $id = 42, string $status = 'PENDING', string $paymentStatus = 'APPROVED'): array
    {
        return ['id' => $id, 'reference' => "TTV-ADMIN-{$id}", 'status' => $status, 'payment_status' => $paymentStatus, 'reservation_status' => 'CONSUMED', 'paid_at' => $paymentStatus === 'APPROVED' ? '2026-09-21T13:04:20.000Z' : null, 'payment' => null, 'customer_name' => 'Cliente de Prueba', 'customer_email' => 'cliente@example.test', 'customer_phone' => '3000000000', 'customer_document' => '1000000000', 'address' => 'Direccion de prueba', 'extra' => null, 'city' => 'Bogota', 'region' => 'Bogota D.C.', 'postal' => null, 'total' => 19500, 'created_at' => '2026-09-21T13:04:20.000Z', 'items' => [['id' => 9, 'product_id' => 193, 'product_name' => 'Producto historico', 'unit_price' => 19500, 'quantity' => 1]]];
    }
}
