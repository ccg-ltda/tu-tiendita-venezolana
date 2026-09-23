<?php

namespace Tests\Feature\Payments;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReleaseExpiredWompiReservationsTest extends TestCase
{
    private const APPS_URL = 'https://apps-script.test/exec';
    private const WOMPI_URL = 'https://sandbox.wompi.co/v1';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.apps_script.url' => self::APPS_URL,
            'services.apps_script.api_key' => 'apps-script-test-key',
            'services.wompi.environment' => 'sandbox',
            'services.wompi.base_url' => self::WOMPI_URL,
            'services.wompi.private_key' => 'prv_test_private_key_for_tests',
        ]);
    }

    public function test_empty_candidates_performs_no_commit_or_wompi_lookup(): void
    {
        Http::fake([self::APPS_URL => Http::response($this->candidates([]), 200)]);

        $this->runCommand()->expectsOutputToContain('candidates=0 released=0 held=0 review_required=0 errors=0');
        Http::assertSentCount(1);
    }

    public function test_candidates_without_attempts_or_with_local_final_attempts_commit_without_wompi(): void
    {
        $candidates = [
            $this->candidate('TTV-NONE'),
            $this->candidate('TTV-DECLINED', [['wompi_transaction_id' => 'txn-declined', 'status' => 'DECLINED', 'amount_in_cents' => 1950000, 'currency' => 'COP', 'updated_at' => '2026-09-21T13:00:00.000Z']]),
            $this->candidate('TTV-VOIDED', [['wompi_transaction_id' => 'txn-voided', 'status' => 'VOIDED', 'amount_in_cents' => 1950000, 'currency' => 'COP', 'updated_at' => '2026-09-21T13:00:00.000Z']]),
            $this->candidate('TTV-ERROR', [['wompi_transaction_id' => 'txn-error', 'status' => 'ERROR', 'amount_in_cents' => 1950000, 'currency' => 'COP', 'updated_at' => '2026-09-21T13:00:00.000Z']]),
        ];
        Http::fake([self::APPS_URL => Http::sequence()->push($this->candidates($candidates), 200)->push($this->commitResult(released: $candidates), 200)]);

        $this->runCommand()->expectsOutputToContain('released=4');
        Http::assertSentCount(2);
    }

    public function test_pending_remote_final_statuses_commit_verified_attempts(): void
    {
        $statuses = ['DECLINED', 'VOIDED', 'ERROR'];
        $candidates = array_map(fn (string $status): array => $this->candidate('TTV-'.$status, [$this->pending('txn-'.$status)]), $statuses);
        $fakes = [self::APPS_URL => Http::sequence()->push($this->candidates($candidates), 200)->push($this->commitResult(released: $candidates), 200)];
        foreach ($candidates as $index => $candidate) {
            $id = $candidate['payment_attempts'][0]['wompi_transaction_id'];
            $fakes[self::WOMPI_URL.'/transactions/'.$id] = Http::response(['data' => $this->transaction($id, $candidate['reference'], $statuses[$index])], 200);
        }
        Http::fake($fakes);

        $this->runCommand()->expectsOutputToContain('released=3');
        Http::assertSent(function ($request): bool {
            if ($request->url() !== self::APPS_URL) return false;
            $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
            return $body['mode'] === 'commit'
                && count($body['releases']) === 3
                && $body['releases'][0]['expected_revision'] === 3
                && $body['releases'][0]['verified_final_attempts'][0]['status'] === 'DECLINED'
                && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $body['releases'][0]['verified_final_attempts'][0]['checked_at']) === 1;
        });
    }

    public function test_more_than_twenty_safe_candidates_are_committed_in_isolated_batches(): void
    {
        $candidates = array_map(fn (int $index): array => $this->candidate('TTV-BATCH-'.$index), range(1, 21));
        Http::fake([self::APPS_URL => Http::sequence()
            ->push($this->candidates($candidates), 200)
            ->push($this->commitResult(released: array_slice($candidates, 0, 20)), 200)
            ->push($this->commitResult(released: array_slice($candidates, 20)), 200)]);

        $this->runCommand()->expectsOutputToContain('released=21');
        $commits = Http::recorded(fn ($request): bool => $request->url() === self::APPS_URL
            && (json_decode($request->body(), true)['mode'] ?? null) === 'commit')->values();
        $this->assertCount(2, $commits);
        $this->assertCount(20, json_decode($commits[0][0]->body(), true)['releases']);
        $this->assertCount(1, json_decode($commits[1][0]->body(), true)['releases']);
    }

    public function test_pending_or_approved_remote_and_local_approved_are_held_without_commit(): void
    {
        $pending = $this->candidate('TTV-REMOTE-PENDING', [$this->pending('txn-pending')]);
        $approved = $this->candidate('TTV-REMOTE-APPROVED', [$this->pending('txn-approved')]);
        $localApproved = $this->candidate('TTV-LOCAL-APPROVED', [['wompi_transaction_id' => 'txn-local', 'status' => 'APPROVED', 'amount_in_cents' => 1950000, 'currency' => 'COP', 'updated_at' => '2026-09-21T13:00:00.000Z']]);
        Http::fake([
            self::APPS_URL => Http::response($this->candidates([$pending, $approved, $localApproved]), 200),
            self::WOMPI_URL.'/transactions/txn-pending' => Http::response(['data' => $this->transaction('txn-pending', $pending['reference'], 'PENDING')], 200),
            self::WOMPI_URL.'/transactions/txn-approved' => Http::response(['data' => $this->transaction('txn-approved', $approved['reference'], 'APPROVED')], 200),
        ]);

        $this->runCommand()->expectsOutputToContain('held=3');
        Http::assertSentCount(3);
    }

    public function test_remote_mismatch_timeout_and_invalid_json_hold_only_unsafe_candidates(): void
    {
        $safe = $this->candidate('TTV-SAFE');
        $referenceMismatch = $this->candidate('TTV-REFERENCE', [$this->pending('txn-reference')]);
        $amountMismatch = $this->candidate('TTV-AMOUNT', [$this->pending('txn-amount')]);
        $currencyMismatch = $this->candidate('TTV-CURRENCY', [$this->pending('txn-currency')]);
        $timeout = $this->candidate('TTV-TIMEOUT', [$this->pending('txn-timeout')]);
        $invalid = $this->candidate('TTV-INVALID', [$this->pending('txn-invalid')]);
        Http::fake([
            self::APPS_URL => Http::sequence()->push($this->candidates([$safe, $referenceMismatch, $amountMismatch, $currencyMismatch, $timeout, $invalid]), 200)->push($this->commitResult(released: [$safe]), 200),
            self::WOMPI_URL.'/transactions/txn-reference' => Http::response(['data' => $this->transaction('txn-reference', 'TTV-OTHER', 'DECLINED')], 200),
            self::WOMPI_URL.'/transactions/txn-amount' => Http::response(['data' => $this->transaction('txn-amount', 'TTV-AMOUNT', 'DECLINED', 1)], 200),
            self::WOMPI_URL.'/transactions/txn-currency' => Http::response(['data' => $this->transaction('txn-currency', 'TTV-CURRENCY', 'DECLINED', 1950000, 'USD')], 200),
            self::WOMPI_URL.'/transactions/txn-timeout' => fn () => throw new ConnectionException('timeout'),
            self::WOMPI_URL.'/transactions/txn-invalid' => Http::response('not-json', 200),
        ]);

        $this->artisan('wompi:release-expired-reservations')
            ->expectsOutputToContain('released=1 held=5')
            ->assertExitCode(0);
    }

    public function test_apps_script_candidates_and_commit_fail_closed(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->artisan('wompi:release-expired-reservations')->assertExitCode(1);

        Http::fake([self::APPS_URL => Http::response('not-json', 200)]);
        $this->artisan('wompi:release-expired-reservations')->assertExitCode(1);

        $candidate = $this->candidate('TTV-COMMIT');
        Http::fake([self::APPS_URL => Http::sequence()->push($this->candidates([$candidate]), 200)->push('not-json', 200)]);
        $this->artisan('wompi:release-expired-reservations')->assertExitCode(1);
    }

    public function test_commit_results_are_counted_and_scheduler_is_preserved(): void
    {
        $candidate = $this->candidate('TTV-RESULT');
        Http::fake([self::APPS_URL => Http::sequence()->push($this->candidates([$candidate]), 200)->push($this->commitResult(released: [], held: [['reference' => 'TTV-RESULT', 'reason' => 'REVISION_CONFLICT']], review: [['reference' => 'TTV-REVIEW', 'reason' => 'PAYMENT_APPROVED']]), 200)]);

        $this->artisan('wompi:release-expired-reservations')
            ->expectsOutputToContain('held=1 review_required=1')
            ->assertExitCode(0);
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'wompi:release-expired-reservations'));
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    private function runCommand()
    {
        return $this->artisan('wompi:release-expired-reservations')->assertExitCode(0);
    }

    private function candidate(string $reference, array $attempts = []): array
    {
        return ['order_id' => 1, 'reference' => $reference, 'revision' => 3, 'reservation_expires_at' => '2026-09-21T12:00:00.000Z', 'total_cop' => 19500, 'payment_attempts' => $attempts];
    }

    private function pending(string $id): array
    {
        return ['wompi_transaction_id' => $id, 'status' => 'PENDING', 'amount_in_cents' => 1950000, 'currency' => 'COP', 'updated_at' => '2026-09-21T13:00:00.000Z'];
    }

    private function candidates(array $candidates): array
    {
        return ['ok' => true, 'data' => ['candidates' => $candidates]];
    }

    private function transaction(string $id, string $reference, string $status, int $amount = 1950000, string $currency = 'COP'): array
    {
        return ['id' => $id, 'reference' => $reference, 'status' => $status, 'amount_in_cents' => $amount, 'currency' => $currency, 'payment_method_type' => 'CARD'];
    }

    private function commitResult(array $released = [], array $held = [], array $review = []): array
    {
        $released = array_map(fn (array $candidate): array => ['order_id' => $candidate['order_id'], 'reference' => $candidate['reference'], 'reservation_status' => 'RELEASED', 'revision' => 4, 'idempotency_replayed' => false], $released);
        return ['ok' => true, 'data' => ['released' => $released, 'held' => $held, 'review_required' => $review]];
    }
}
