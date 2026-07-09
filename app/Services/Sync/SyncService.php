<?php

namespace App\Services\Sync;

use App\Models\ImportError;
use App\Models\PaypalAccount;
use App\Models\SyncRun;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PayPal\Exceptions\PayPalApiException;
use App\Services\PayPal\Exceptions\PayPalAuthException;
use App\Services\PayPal\Exceptions\PayPalPermissionException;
use App\Services\PayPal\Exceptions\PayPalRateLimitException;
use App\Services\PayPal\Exceptions\PayPalResultSetTooLargeException;
use App\Services\PayPal\PayPalClient;
use App\Services\PayPal\TransactionSearchClient;
use Carbon\CarbonInterface;

/**
 * Orchestrates a full sync run for one PayPal account over a requested
 * date range: splits the range into API-compatible windows, fetches and
 * normalizes every transaction, assigns events, upserts idempotently, and
 * records everything on the SyncRun/ImportError models.
 */
class SyncService
{
    public function __construct(
        private readonly TransactionNormalizer $normalizer,
        private readonly EventAssigner $assigner,
    ) {
    }

    /**
     * @param  array<string,mixed>  $extraParams  optional PayPal query params (transaction_status, transaction_currency, ...)
     */
    public function run(
        PaypalAccount $account,
        CarbonInterface $start,
        CarbonInterface $end,
        string $type = SyncRun::TYPE_MANUAL,
        ?User $triggeredBy = null,
        array $extraParams = [],
    ): SyncRun {
        $run = SyncRun::create([
            'paypal_account_id' => $account->id,
            'type' => $type,
            'status' => SyncRun::STATUS_RUNNING,
            'window_start' => $start,
            'window_end' => $end,
            'started_at' => now(),
            'triggered_by_user_id' => $triggeredBy?->id,
        ]);

        $hadErrors = false;

        try {
            $windows = WindowSplitter::splitToMaxDays($start, $end, config('paypal.max_window_days', 31));

            foreach ($windows as [$subStart, $subEnd]) {
                try {
                    $this->syncWindow($account, $run, $subStart, $subEnd, $extraParams);
                } catch (PayPalApiException $e) {
                    $hadErrors = true;

                    if ($e instanceof PayPalAuthException || $e instanceof PayPalPermissionException) {
                        throw $e; // fatal for the whole run - credentials/permissions are broken
                    }
                }
            }

            $account->forceFill([
                'last_synced_at' => now(),
                'last_successful_sync_at' => $hadErrors ? $account->last_successful_sync_at : now(),
                'last_error' => null,
            ])->save();

            $run->markFinished($hadErrors ? SyncRun::STATUS_PARTIAL : SyncRun::STATUS_SUCCESS);
        } catch (PayPalApiException $e) {
            $run->forceFill(['error_message' => $e->getMessage()])->save();

            $account->forceFill([
                'last_synced_at' => now(),
                'last_error' => $e->getMessage(),
            ])->save();

            $run->markFinished(SyncRun::STATUS_FAILED);

            throw $e;
        }

        return $run->fresh();
    }

    private function syncWindow(
        PaypalAccount $account,
        SyncRun $run,
        CarbonInterface $start,
        CarbonInterface $end,
        array $extraParams,
        int $splitStepIndex = 0,
    ): void {
        $client = new TransactionSearchClient(new PayPalClient($account));

        try {
            $stats = $client->searchAll($start, $end, function (array $raw) use ($account, $run) {
                $this->ingest($account, $run, $raw);
            }, $extraParams);

            $run->increment('api_requests_count', $stats['api_requests']);
        } catch (PayPalResultSetTooLargeException $e) {
            $steps = config('paypal.window_split_steps', []);

            if (! isset($steps[$splitStepIndex])) {
                $this->recordWindowError($run, $account, $start, $end, ImportError::TYPE_RESULTSET_TOO_LARGE, $e);
                $run->increment('error_count');

                return;
            }

            foreach (WindowSplitter::splitByIsoDuration($start, $end, $steps[$splitStepIndex]) as [$subStart, $subEnd]) {
                $this->syncWindow($account, $run, $subStart, $subEnd, $extraParams, $splitStepIndex + 1);
            }
        } catch (PayPalRateLimitException $e) {
            $this->recordWindowError($run, $account, $start, $end, ImportError::TYPE_RATE_LIMIT, $e);

            throw $e;
        } catch (PayPalApiException $e) {
            $this->recordWindowError($run, $account, $start, $end, $this->classify($e), $e);

            throw $e;
        }
    }

    private function ingest(PaypalAccount $account, SyncRun $run, array $raw): void
    {
        try {
            $normalized = $this->normalizer->normalize($account, $raw);

            if (empty($normalized['transaction_id'])) {
                throw new \RuntimeException('Datensatz ohne transaction_id übersprungen.');
            }

            $status = $this->upsert($account, $normalized);
            $run->increment("{$status}_count");
        } catch (\Throwable $e) {
            $run->increment('error_count');

            ImportError::create([
                'sync_run_id' => $run->id,
                'paypal_account_id' => $account->id,
                'transaction_id' => $raw['transaction_info']['transaction_id'] ?? null,
                'error_type' => ImportError::TYPE_VALIDATION,
                'message' => $e->getMessage(),
                'context' => ['raw' => $raw],
            ]);
        }
    }

    /**
     * Idempotent upsert keyed on dedupe_key. Because the dedupe key embeds
     * transaction_updated_date + raw_hash, a genuinely changed PayPal
     * transaction (status transition, fee correction, ...) creates a new
     * revision row rather than overwriting history, while an exact repeat
     * (e.g. re-fetched via the lookback window) is a no-op.
     */
    private function upsert(PaypalAccount $account, array $normalized): string
    {
        $existing = Transaction::query()->where('dedupe_key', $normalized['dedupe_key'])->first();

        if ($existing) {
            $existing->forceFill(['last_seen_at' => now()])->save();

            return 'skipped';
        }

        $hasPriorRevision = Transaction::query()
            ->where('paypal_account_id', $account->id)
            ->where('transaction_id', $normalized['transaction_id'])
            ->exists();

        $assignment = $this->assigner->assign($normalized);

        Transaction::create(array_merge($normalized, $assignment, [
            'imported_at' => now(),
            'last_seen_at' => now(),
        ]));

        return $hasPriorRevision ? 'updated' : 'imported';
    }

    private function recordWindowError(
        SyncRun $run,
        PaypalAccount $account,
        CarbonInterface $start,
        CarbonInterface $end,
        string $type,
        PayPalApiException $e,
    ): void {
        ImportError::create([
            'sync_run_id' => $run->id,
            'paypal_account_id' => $account->id,
            'window_start' => $start,
            'window_end' => $end,
            'error_type' => $type,
            'message' => $e->getMessage(),
            'context' => $e->context,
        ]);
    }

    private function classify(PayPalApiException $e): string
    {
        return match (true) {
            $e instanceof PayPalAuthException, $e instanceof PayPalPermissionException => ImportError::TYPE_AUTH,
            $e instanceof PayPalRateLimitException => ImportError::TYPE_RATE_LIMIT,
            $e instanceof PayPalResultSetTooLargeException => ImportError::TYPE_RESULTSET_TOO_LARGE,
            default => ImportError::TYPE_API_ERROR,
        };
    }
}
