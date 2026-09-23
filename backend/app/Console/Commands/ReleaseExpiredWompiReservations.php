<?php

namespace App\Console\Commands;

use App\Services\AppsScriptCheckoutClient;
use App\Services\AppsScriptCheckoutException;
use App\Support\Payments\WompiTransactionClient;
use App\Support\Payments\WompiTransactionException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReleaseExpiredWompiReservations extends Command
{
    protected $signature = 'wompi:release-expired-reservations';

    protected $description = 'Safely releases expired Wompi inventory reservations.';

    public function handle(AppsScriptCheckoutClient $checkout, WompiTransactionClient $wompi): int
    {
        try {
            // Pull up to 50 candidates, then commit isolated batches of at most 20.
            $candidates = $checkout->getExpiredReservationCandidates(50);
        } catch (AppsScriptCheckoutException $exception) {
            Log::warning('Wompi expired-reservation candidates are unavailable.', ['status' => $exception->status()]);
            $this->error('Candidates are unavailable; released=0 held=0 review_required=0 errors=1.');

            return self::FAILURE;
        }

        $summary = ['candidates' => count($candidates), 'released' => 0, 'held' => 0, 'review_required' => 0, 'errors' => 0];
        $releases = [];
        foreach ($candidates as $candidate) {
            $release = $this->verifiedRelease($candidate, $wompi);
            if ($release === null) {
                ++$summary['held'];
                continue;
            }
            $releases[] = $release;
        }

        foreach (array_chunk($releases, 20) as $batch) {
            try {
                $result = $checkout->commitExpiredReservations($batch);
            } catch (AppsScriptCheckoutException $exception) {
                Log::warning('Wompi expired-reservation commit is unavailable.', ['status' => $exception->status()]);
                ++$summary['errors'];
                $this->printSummary($summary);

                return self::FAILURE;
            }

            $summary['released'] += count($result['released']);
            $summary['held'] += count($result['held']);
            $summary['review_required'] += count($result['review_required']);
        }

        $this->printSummary($summary);

        return self::SUCCESS;
    }

    /**
     * @param array{reference: string, revision: int, total_cop: int, payment_attempts: list<array{wompi_transaction_id: string, status: string, amount_in_cents: int, currency: string, updated_at: string}>} $candidate
     * @return array{reference: string, expected_revision: int, verified_final_attempts: list<array{wompi_transaction_id: string, status: string, checked_at: string}>}|null
     */
    private function verifiedRelease(array $candidate, WompiTransactionClient $wompi): ?array
    {
        $verified = [];
        foreach ($candidate['payment_attempts'] as $attempt) {
            if ($attempt['status'] === 'APPROVED') {
                return null;
            }
            if (in_array($attempt['status'], ['DECLINED', 'VOIDED', 'ERROR'], true)) {
                continue;
            }
            if ($attempt['status'] !== 'PENDING') {
                return null;
            }

            try {
                $transaction = $wompi->fetch($attempt['wompi_transaction_id']);
            } catch (WompiTransactionException) {
                return null;
            }

            if (! $this->matchesCandidate($transaction, $candidate, $attempt['wompi_transaction_id'])
                || ! in_array($transaction['status'], ['DECLINED', 'VOIDED', 'ERROR'], true)) {
                return null;
            }

            $verified[] = [
                'wompi_transaction_id' => $transaction['id'],
                'status' => $transaction['status'],
                'checked_at' => now('UTC')->format('Y-m-d\\TH:i:s.v\\Z'),
            ];
        }

        return [
            'reference' => $candidate['reference'],
            'expected_revision' => $candidate['revision'],
            'verified_final_attempts' => $verified,
        ];
    }

    /**
     * @param array{id: string, reference: string, status: string, amount_in_cents: int, currency: string, payment_method_type: string|null} $transaction
     * @param array{reference: string, total_cop: int} $candidate
     */
    private function matchesCandidate(array $transaction, array $candidate, string $expectedTransactionId): bool
    {
        return $transaction['id'] === $expectedTransactionId
            && $transaction['reference'] === $candidate['reference']
            && $transaction['amount_in_cents'] === $candidate['total_cop'] * 100
            && $transaction['currency'] === 'COP';
    }

    /** @param array{candidates: int, released: int, held: int, review_required: int, errors: int} $summary */
    private function printSummary(array $summary): void
    {
        $this->info(implode(' ', array_map(
            static fn (string $key, int $value): string => "{$key}={$value}",
            array_keys($summary),
            $summary,
        )));
    }
}
