<?php

namespace Tests\Feature\Payments;

use App\Services\WompiPaymentEventOutboxStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncPendingWompiPaymentEventsTest extends TestCase
{
    private const URL = 'https://apps-script.test/exec';

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.apps_script.url' => self::URL, 'services.apps_script.api_key' => 'apps-script-test-key']);
        $this->path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wompi-sync-'.bin2hex(random_bytes(6)).'.json';
        $this->app->instance(WompiPaymentEventOutboxStore::class, new WompiPaymentEventOutboxStore($this->path));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_successful_retry_removes_the_durable_event(): void
    {
        $this->outbox()->enqueue($this->event('APPROVED'));
        Http::fake([self::URL => Http::response($this->success(), 200)]);

        $this->artisan('wompi:sync-pending-payment-events')
            ->expectsOutput('processed=1 synced=1 pending=0')
            ->assertExitCode(0);

        $this->assertSame([], $this->outbox()->all());
        Http::assertSent(fn ($request): bool => $this->requestEvent($request)['status'] === 'APPROVED');
    }

    public function test_failed_retry_keeps_the_event_and_records_an_attempt(): void
    {
        $this->outbox()->enqueue($this->event('PENDING'));
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timeout'));

        $this->artisan('wompi:sync-pending-payment-events')
            ->expectsOutput('processed=1 synced=0 pending=1')
            ->assertExitCode(1);

        $events = $this->outbox()->all();
        $this->assertCount(1, $events);
        $this->assertSame(1, $events[0]['attempts']);
        $this->assertSame('apps_script_unavailable', $events[0]['last_error']);
    }

    public function test_same_transaction_event_is_compacted_and_terminal_statuses_are_retryable(): void
    {
        foreach (['DECLINED', 'VOIDED', 'ERROR'] as $status) {
            $this->outbox()->enqueue($this->event($status, 'transaction-'.$status));
        }
        $older = $this->event('PENDING', 'transaction-duplicate');
        $newer = $this->event('APPROVED', 'transaction-duplicate', '2024-08-30T06:41:00.000Z');
        $this->outbox()->enqueue($older);
        $this->outbox()->enqueue($newer);

        $events = $this->outbox()->all();
        $this->assertCount(4, $events);
        $duplicate = collect($events)->firstWhere('transaction.id', 'transaction-duplicate');
        $this->assertSame('APPROVED', $duplicate['transaction']['status']);
    }

    public function test_scheduler_runs_the_sync_every_minute_without_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'wompi:sync-pending-payment-events'));

        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame('* * * * *', $event->expression);
    }

    private function outbox(): WompiPaymentEventOutboxStore
    {
        return app(WompiPaymentEventOutboxStore::class);
    }

    /** @return array{id:string,reference:string,status:string,payment_method:string,amount_in_cents:int,currency:string,event_occurred_at:string} */
    private function event(string $status, string $id = 'transaction-1', string $occurredAt = '2024-08-30T06:40:00.000Z'): array
    {
        return ['id' => $id, 'reference' => 'TTV-WEBHOOK-1', 'status' => $status, 'payment_method' => 'CARD', 'amount_in_cents' => 100000, 'currency' => 'COP', 'event_occurred_at' => $occurredAt];
    }

    private function requestEvent($request): array
    {
        return json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR)['transaction'];
    }

    private function success(): array
    {
        return ['ok' => true, 'data' => ['order_id' => 77, 'payment_attempt_id' => 12, 'payment_event_replayed' => false, 'event_result' => 'APPROVED', 'status' => 'PENDING', 'payment_status' => 'APPROVED', 'reservation_status' => 'CONSUMED', 'revision' => 2]];
    }
}
