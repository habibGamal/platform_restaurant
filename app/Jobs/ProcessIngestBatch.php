<?php

namespace App\Jobs;

use App\Domain\Ingestion\Contracts\DocumentHandlerRegistry;
use App\Domain\Ingestion\Contracts\HandleResult;
use App\Domain\Ingestion\Exceptions\FencedWorkerException;
use App\Domain\Ingestion\Support\DirtyDateOutbox;
use App\Models\Branch;
use App\Models\IngestBatch;
use App\Models\IngestRejection;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ProcessIngestBatch implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 3;

    public static int $defaultLeaseDurationSeconds = 30;

    /**
     * Optional hook for testing crash recovery immediately after document commit.
     *
     * @var (?(\Closure(array<string, mixed>, self): void))|null
     */
    public static ?\Closure $afterDocumentCommitted = null;

    /**
     * @param  list<string>  $acceptedUuids
     * @param  list<int>|null  $acceptedIndices
     */
    public function __construct(
        public int $batchId,
        public array $acceptedUuids = [],
        public ?int $branchId = null,
        public ?array $acceptedIndices = null,
    ) {
        $this->onQueue('ingest');
    }

    /**
     * Resolve queue retry_after for active connection, supporting non-database queue drivers.
     */
    public static function getRetryAfterForConnection(?string $connectionName = null): int
    {
        $connection = $connectionName ?: config('queue.default', 'database');
        $retryAfter = config("queue.connections.{$connection}.retry_after")
            ?? config("queue.connections.{$connection}.visibility_timeout");

        if (is_numeric($retryAfter) && (int) $retryAfter > 0) {
            return (int) $retryAfter;
        }

        return (int) config('queue.connections.database.retry_after', 90);
    }

    /**
     * Get the active lease duration in seconds.
     */
    public static function getLeaseDuration(): int
    {
        return (int) config('ingest.lease_duration_seconds', static::$defaultLeaseDurationSeconds);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        $branchId = $this->branchId ?? IngestBatch::where('id', $this->batchId)->value('branch_id');
        if (! $branchId) {
            return [];
        }

        $retryAfter = static::getRetryAfterForConnection($this->connection);

        return [
            (new WithoutOverlapping("branch-{$branchId}"))
                ->expireAfter($retryAfter)
                ->releaseAfter(10),
        ];
    }

    /**
     * Mark batch as failed verifying fencing ownership.
     */
    protected function failBatch(int $batchId, string $claimToken, int $fencingToken, string $error): void
    {
        DB::transaction(function () use ($batchId, $claimToken, $fencingToken, $error): void {
            /** @var IngestBatch|null $currentBatch */
            $currentBatch = IngestBatch::where('id', $batchId)
                ->lockForUpdate()
                ->first();

            if (! $currentBatch
                || $currentBatch->claim_token !== $claimToken
                || (int) $currentBatch->fencing_token !== $fencingToken
                || $currentBatch->status === 'processed'
            ) {
                return;
            }

            $now = Carbon::now();
            $currentBatch->update([
                'status' => 'failed',
                'error' => $error,
                'processed_at' => $now,
                'lease_expires_at' => null,
                'updated_at' => $now,
            ]);
        });
    }

    /**
     * Atomically claim the batch lease with a unique claim token and fencing token.
     * Returns array{claim_token: string, fencing_token: int}|null
     *
     * @return array{claim_token: string, fencing_token: int}|null
     */
    protected function claimBatch(int $batchId, int $leaseDuration): ?array
    {
        return DB::transaction(function () use ($batchId, $leaseDuration): ?array {
            /** @var IngestBatch|null $batch */
            $batch = IngestBatch::where('id', $batchId)->lockForUpdate()->first();

            if (! $batch) {
                return null;
            }

            if ($batch->status === 'processed' || $batch->status === 'failed') {
                return null;
            }

            $now = Carbon::now();

            if ($batch->status === 'processing') {
                // Active worker with fresh lease in the future
                if ($batch->lease_expires_at !== null && $batch->lease_expires_at->gt($now)) {
                    return null;
                }

                // Legacy fallback for records created without lease_expires_at
                if ($batch->lease_expires_at === null) {
                    $staleCutoff = $now->copy()->subSeconds(static::getRetryAfterForConnection($this->connection));
                    if ($batch->updated_at->gt($staleCutoff)) {
                        return null;
                    }
                }
            }

            $claimToken = (string) Str::uuid();
            $nextFencingToken = ((int) $batch->fencing_token) + 1;
            $expiresAt = $now->copy()->addSeconds($leaseDuration);

            $batch->update([
                'status' => 'processing',
                'claim_token' => $claimToken,
                'fencing_token' => $nextFencingToken,
                'lease_expires_at' => $expiresAt,
                'heartbeat_at' => $now,
                'updated_at' => $now,
            ]);

            return [
                'claim_token' => $claimToken,
                'fencing_token' => $nextFencingToken,
            ];
        });
    }

    public function handle(DocumentHandlerRegistry $registry): void
    {
        $leaseDuration = static::getLeaseDuration();
        $claim = $this->claimBatch($this->batchId, $leaseDuration);

        if ($claim === null) {
            return;
        }

        $claimToken = $claim['claim_token'];
        $fencingToken = $claim['fencing_token'];

        $batch = IngestBatch::find($this->batchId);
        if (! $batch) {
            return;
        }

        $branch = $batch->branch ?? Branch::find($batch->branch_id);
        if (! $branch) {
            $this->failBatch($this->batchId, $claimToken, $fencingToken, 'Branch not found.');

            return;
        }

        if (empty($batch->storage_path) || ! Storage::disk('local')->exists($batch->storage_path)) {
            $this->failBatch($this->batchId, $claimToken, $fencingToken, 'Storage file not found or unreadable.');

            return;
        }

        try {
            $content = Storage::disk('local')->get($batch->storage_path);
        } catch (Throwable $e) {
            $this->failBatch($this->batchId, $claimToken, $fencingToken, 'Storage file read error: '.$e->getMessage());
            report($e);

            return;
        }

        if ($content === null || $content === '') {
            $this->failBatch($this->batchId, $claimToken, $fencingToken, 'Storage file is empty or unreadable.');

            return;
        }

        if (str_ends_with($batch->storage_path, '.gz')) {
            $decompressed = @gzdecode($content);
            if ($decompressed === false) {
                $this->failBatch($this->batchId, $claimToken, $fencingToken, 'Failed to decompress gzip content.');

                return;
            }
            $json = $decompressed;
        } else {
            $json = $content;
        }

        $envelope = json_decode($json, true);
        if (! is_array($envelope) || ! isset($envelope['documents']) || ! is_array($envelope['documents'])) {
            $this->failBatch($this->batchId, $claimToken, $fencingToken, 'Invalid JSON in stored batch.');

            return;
        }

        $rawResult = $batch->getAttribute('result');
        $batchResult = is_array($rawResult) ? $rawResult : [];
        $acceptedIndices = $this->acceptedIndices;
        if ($acceptedIndices === null && isset($batchResult['_accepted_indices']) && is_array($batchResult['_accepted_indices'])) {
            /** @var list<int> $acceptedIndices */
            $acceptedIndices = array_values($batchResult['_accepted_indices']);
        }

        $acceptedIndexSet = $acceptedIndices !== null ? array_flip($acceptedIndices) : null;

        $acceptedUuids = ! empty($this->acceptedUuids)
            ? $this->acceptedUuids
            : (isset($batchResult['accepted']) && is_array($batchResult['accepted']) ? array_values($batchResult['accepted']) : []);
        $acceptedSet = array_flip($acceptedUuids);

        // Build rejected doc pairs (type:uuid) to guarantee rejected docs never run even if acceptedIndexSet is null
        $rejectedPairs = [];
        if (isset($batchResult['rejected']) && is_array($batchResult['rejected'])) {
            foreach ($batchResult['rejected'] as $rej) {
                if (is_array($rej) && isset($rej['type'], $rej['uuid']) && is_string($rej['type']) && is_string($rej['uuid'])) {
                    $rejectedPairs[$rej['type'].':'.$rej['uuid']] = true;
                }
            }
        }
        $rejections = IngestRejection::where('ingest_batch_id', $batch->id)->get(['doc_type', 'doc_uuid']);
        foreach ($rejections as $rej) {
            if ($rej->doc_uuid !== null) {
                $rejectedPairs[$rej->doc_type.':'.$rej->doc_uuid] = true;
            }
        }

        $dirtyDates = [];

        foreach ($envelope['documents'] as $index => $doc) {
            if (! is_array($doc) || ! isset($doc['uuid'])) {
                continue;
            }

            $docType = (string) ($doc['type'] ?? 'unknown');
            $docUuid = (string) $doc['uuid'];

            if ($acceptedIndexSet !== null) {
                if (! isset($acceptedIndexSet[$index])) {
                    continue;
                }
            } else {
                if (! isset($acceptedSet[$docUuid])) {
                    continue;
                }
                if (isset($rejectedPairs[$docType.':'.$docUuid])) {
                    continue;
                }
            }

            try {
                /** @var HandleResult $result */
                $result = DB::transaction(function () use (
                    $registry,
                    $branch,
                    $doc,
                    $claimToken,
                    $fencingToken,
                    $leaseDuration
                ): HandleResult {
                    // Atomically lock batch and check fencing token
                    /** @var IngestBatch|null $currentBatch */
                    $currentBatch = IngestBatch::where('id', $this->batchId)
                        ->lockForUpdate()
                        ->first();

                    if (! $currentBatch
                        || $currentBatch->claim_token !== $claimToken
                        || (int) $currentBatch->fencing_token !== $fencingToken
                    ) {
                        throw new FencedWorkerException(
                            "Worker fenced: lost ownership of batch {$this->batchId}."
                        );
                    }

                    // Renew lease on heartbeat
                    $now = Carbon::now();
                    $currentBatch->update([
                        'lease_expires_at' => $now->copy()->addSeconds($leaseDuration),
                        'heartbeat_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $res = $registry->for($doc['type'])->handle($branch, $doc);

                    if ($res->applied && ! empty($res->dirtyDates)) {
                        DirtyDateOutbox::record($this->batchId, $branch, $res->dirtyDates);
                    }

                    return $res;
                });

                if ($result->applied && ! empty($result->dirtyDates)) {
                    foreach ($result->dirtyDates as $date) {
                        $dirtyDates[(string) $date] = true;
                    }
                }
            } catch (FencedWorkerException $e) {
                // Stale worker fenced! Must abort immediately.
                // Do NOT record rejection, do NOT mark processed, do NOT update seq or aggregates.
                report($e);

                return;
            } catch (Throwable $e) {
                // Handler failed for this document: record rejection IF NOT FENCED
                $recorded = DB::transaction(function () use (
                    $branch,
                    $doc,
                    $envelope,
                    $e,
                    $claimToken,
                    $fencingToken,
                    $leaseDuration
                ): bool {
                    /** @var IngestBatch|null $currentBatch */
                    $currentBatch = IngestBatch::where('id', $this->batchId)
                        ->lockForUpdate()
                        ->first();

                    if (! $currentBatch
                        || $currentBatch->claim_token !== $claimToken
                        || (int) $currentBatch->fencing_token !== $fencingToken
                    ) {
                        return false;
                    }

                    $now = Carbon::now();
                    $currentBatch->update([
                        'lease_expires_at' => $now->copy()->addSeconds($leaseDuration),
                        'heartbeat_at' => $now,
                        'updated_at' => $now,
                    ]);

                    IngestRejection::firstOrCreate([
                        'organization_id' => $branch->organization_id,
                        'branch_id' => $branch->id,
                        'ingest_batch_id' => $this->batchId,
                        'doc_type' => substr((string) ($doc['type'] ?? 'unknown'), 0, 32),
                        'doc_uuid' => is_string($doc['uuid']) ? substr($doc['uuid'], 0, 36) : null,
                    ], [
                        'reason' => $e->getMessage(),
                        'pos_version' => (string) ($envelope['pos_version'] ?? $branch->pos_version),
                    ]);

                    return true;
                });

                if (! $recorded) {
                    // Fenced while recording rejection: abort immediately!
                    return;
                }
            }

            if (static::$afterDocumentCommitted !== null) {
                (static::$afterDocumentCommitted)($doc, $this);
            }
        }

        // Batch completion: atomically verify fencing token, transition status and update sequence
        $completed = DB::transaction(function () use (
            $branch,
            $envelope,
            $claimToken,
            $fencingToken
        ): bool {
            /** @var IngestBatch|null $currentBatch */
            $currentBatch = IngestBatch::where('id', $this->batchId)
                ->lockForUpdate()
                ->first();

            if (! $currentBatch
                || $currentBatch->claim_token !== $claimToken
                || (int) $currentBatch->fencing_token !== $fencingToken
            ) {
                return false;
            }

            if ($currentBatch->status === 'processed') {
                return false;
            }

            $now = Carbon::now();
            $currentBatch->update([
                'status' => 'processed',
                'processed_at' => $now,
                'lease_expires_at' => null,
                'updated_at' => $now,
            ]);

            if (isset($envelope['seq_to']) && is_numeric($envelope['seq_to'])) {
                $seqTo = (int) $envelope['seq_to'];
                $branchToUpdate = Branch::where('id', $branch->id)->lockForUpdate()->first();
                if ($branchToUpdate) {
                    $currentSeq = (int) ($branchToUpdate->last_seq ?? 0);
                    $branchToUpdate->update(['last_seq' => max($currentSeq, $seqTo)]);
                }
            }

            return true;
        });

        if (! $completed) {
            return;
        }

        $batchDatesWithGen = DB::table('ingest_dirty_dates')
            ->where('ingest_batch_id', $this->batchId)
            ->where('branch_id', $branch->id)
            ->get(['business_date', 'generation']);

        $seenDates = [];
        foreach ($batchDatesWithGen as $row) {
            $dateStr = $row->business_date instanceof \DateTimeInterface
                ? $row->business_date->format('Y-m-d')
                : (string) $row->business_date;
            $gen = (int) $row->generation;
            $seenDates[$dateStr] = true;

            DirtyDateOutbox::markDispatched($this->batchId, $branch->id, $dateStr, $gen);

            try {
                RecomputeBranchDay::dispatch($branch->id, $dateStr)->delay(now()->addSeconds(30));
            } catch (Throwable $e) {
                DirtyDateOutbox::markFailed($this->batchId, $branch->id, $dateStr, $e->getMessage(), $gen);
                report($e);
            }
        }

        foreach (array_keys($dirtyDates) as $dateStr) {
            if (! isset($seenDates[(string) $dateStr])) {
                DirtyDateOutbox::markDispatched($this->batchId, $branch->id, (string) $dateStr);

                try {
                    RecomputeBranchDay::dispatch($branch->id, (string) $dateStr)->delay(now()->addSeconds(30));
                } catch (Throwable $e) {
                    DirtyDateOutbox::markFailed($this->batchId, $branch->id, (string) $dateStr, $e->getMessage());
                    report($e);
                }
            }
        }
    }
}
