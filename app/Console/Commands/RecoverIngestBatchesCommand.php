<?php

namespace App\Console\Commands;

use App\Domain\Ingestion\Support\DirtyDateOutbox;
use App\Domain\Metrics\Contracts\BranchDayAggregator;
use App\Jobs\ProcessIngestBatch;
use App\Models\IngestBatch;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class RecoverIngestBatchesCommand extends Command
{
    protected $signature = 'ingest:recover {--limit=100} {--stale-seconds=} {--sync}';

    protected $description = 'Recover uncompleted or stale ingestion batches and aggregate recomputations';

    public function handle(BranchDayAggregator $aggregator): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $staleOption = $this->option('stale-seconds');
        $staleSeconds = is_numeric($staleOption)
            ? (int) $staleOption
            : ProcessIngestBatch::getRetryAfterForConnection();

        $now = Carbon::now();
        $staleCutoff = $now->copy()->subSeconds($staleSeconds);
        $receivedCutoff = $now->copy()->subSeconds(30);

        $batches = IngestBatch::where(function ($query) use ($receivedCutoff, $staleCutoff, $now) {
            $query->where(function ($q) use ($receivedCutoff) {
                $q->where('status', 'received')
                    ->where('received_at', '<=', $receivedCutoff);
            })->orWhere(function ($q) use ($staleCutoff, $now) {
                $q->where('status', 'processing')
                    ->where(function ($sub) use ($staleCutoff, $now) {
                        $sub->where(function ($s) use ($now) {
                            $s->whereNotNull('lease_expires_at')
                                ->where('lease_expires_at', '<=', $now);
                        })->orWhere(function ($s) use ($staleCutoff) {
                            $s->whereNull('lease_expires_at')
                                ->where('updated_at', '<=', $staleCutoff);
                        });
                    });
            });
        })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $dispatchedCount = 0;

        foreach ($batches as $batch) {
            $rawResult = $batch->getAttribute('result');
            $batchResult = is_array($rawResult) ? $rawResult : [];

            /** @var list<string> $acceptedUuids */
            $acceptedUuids = isset($batchResult['accepted']) && is_array($batchResult['accepted'])
                ? array_values($batchResult['accepted'])
                : [];

            /** @var list<int>|null $acceptedIndices */
            $acceptedIndices = isset($batchResult['_accepted_indices']) && is_array($batchResult['_accepted_indices'])
                ? array_values($batchResult['_accepted_indices'])
                : null;

            try {
                ProcessIngestBatch::dispatch(
                    $batch->id,
                    $acceptedUuids,
                    $batch->branch_id,
                    $acceptedIndices
                );

                $dispatchedCount++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->info("Dispatched recovery for {$dispatchedCount} batches.");

        $sync = (bool) $this->option('sync');
        $dispatchedDatesCount = DirtyDateOutbox::recover($limit, $staleCutoff, $sync, $aggregator);

        if ($dispatchedDatesCount > 0) {
            $this->info("Dispatched recovery for {$dispatchedDatesCount} dirty dates.");
        }

        return self::SUCCESS;
    }
}
