<?php

namespace Tests\Feature\Ingestion;

use App\Domain\Ingestion\Exceptions\FencedWorkerException;
use App\Domain\Ingestion\Support\DirtyDateOutbox;
use App\Domain\Metrics\Contracts\BranchDayAggregator;
use App\Enums\BranchStatus;
use App\Jobs\ProcessIngestBatch;
use App\Jobs\RecomputeBranchDay;
use App\Models\Branch;
use App\Models\IngestBatch;
use App\Models\IngestDirtyDate;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Warehouse\WhOrder;
use App\Models\Warehouse\WhPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class DirtyDateOutboxRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Restaurant $restaurant;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Regression Org',
            'slug' => 'regression-org',
            'plan' => 'pro',
            'timezone' => 'Africa/Cairo',
            'currency' => 'EGP',
        ]);

        $this->restaurant = Restaurant::create([
            'organization_id' => $this->organization->id,
            'name' => 'Regression Restaurant',
        ]);

        $this->branch = Branch::create([
            'organization_id' => $this->organization->id,
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Main Branch',
            'timezone' => 'Africa/Cairo',
            'opened_on' => '2026-09-01',
            'status' => BranchStatus::ACTIVE,
        ]);
    }

    protected function createBatch(): IngestBatch
    {
        return IngestBatch::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'batch_uuid' => (string) Str::uuid(),
            'schema_version' => 1,
            'pos_version' => '2.4.0',
            'seq_from' => 1,
            'seq_to' => 1,
            'docs_count' => 1,
            'accepted_count' => 1,
            'rejected_count' => 0,
            'status' => 'processed',
        ]);
    }

    /**
     * Regression Test 1:
     * Same-key mutation during aggregation must NOT be marked processed,
     * generation must advance atomically, and pending updates must trigger follow-up recompute.
     */
    public function test_same_key_mutation_during_aggregation_is_not_cleared_and_triggers_reschedule(): void
    {
        Queue::fake([RecomputeBranchDay::class]);

        $batch = $this->createBatch();
        $date = '2026-10-29';

        // 1. Initial document records dirty date
        DirtyDateOutbox::record($batch->id, $this->branch, [$date]);

        $initialRow = IngestDirtyDate::where('branch_id', $this->branch->id)
            ->where('business_date', $date)
            ->firstOrFail();

        $this->assertSame(1, (int) $initialRow->generation);
        $this->assertSame('pending', $initialRow->status);

        // 2. Wrap aggregator so that during recompute, the SAME key is mutated
        $mockAggregator = new class($this->branch, $batch->id, $date) implements BranchDayAggregator
        {
            public bool $mutationTriggered = false;

            public function __construct(
                private Branch $branch,
                private int $batchId,
                private string $date,
            ) {}

            public function recompute(int $branchId, string $businessDate): void
            {
                // Simulate warehouse mutation and DirtyDateOutbox::record within transaction
                DB::transaction(function () {
                    DirtyDateOutbox::record($this->batchId, $this->branch, [$this->date]);
                });
                $this->mutationTriggered = true;
            }
        };

        // 3. Execute RecomputeBranchDay job
        $job = new RecomputeBranchDay($this->branch->id, $date);
        $job->handle($mockAggregator);

        $this->assertTrue($mockAggregator->mutationTriggered);

        // 4. Verify the row was NOT marked processed because generation incremented
        $reloaded = IngestDirtyDate::where('branch_id', $this->branch->id)
            ->where('business_date', $date)
            ->firstOrFail();

        $this->assertSame(2, (int) $reloaded->generation);
        $this->assertSame('pending', $reloaded->status);
        $this->assertNull($reloaded->recomputed_at);

        // Pending updates must exist and reschedule RecomputeBranchDay
        $this->assertTrue(DirtyDateOutbox::hasPendingUpdates($this->branch->id, $date));
        Queue::assertPushed(RecomputeBranchDay::class, function ($pushedJob) use ($date) {
            return $pushedJob->branchId === $this->branch->id && $pushedJob->businessDate === $date;
        });
    }

    /**
     * Regression Test 2:
     * When there are no initial rows before aggregation, any row inserted during aggregation
     * must NOT be cleared by markRecomputed (empty snapshot clears nothing).
     */
    public function test_no_initial_rows_then_insertion_during_aggregation_clears_nothing(): void
    {
        Queue::fake([RecomputeBranchDay::class]);

        $batch = $this->createBatch();
        $date = '2026-10-29';

        // Ensure no rows exist before recompute
        $this->assertSame(0, IngestDirtyDate::where('branch_id', $this->branch->id)->where('business_date', $date)->count());

        $mockAggregator = new class($this->branch, $batch->id, $date) implements BranchDayAggregator
        {
            public ?int $insertedId = null;

            public function __construct(
                private Branch $branch,
                private int $batchId,
                private string $date,
            ) {}

            public function recompute(int $branchId, string $businessDate): void
            {
                DB::transaction(function () {
                    DirtyDateOutbox::record($this->batchId, $this->branch, [$this->date]);
                });
                $this->insertedId = (int) DB::table('ingest_dirty_dates')
                    ->where('branch_id', $this->branch->id)
                    ->where('business_date', $this->date)
                    ->value('id');
            }
        };

        $job = new RecomputeBranchDay($this->branch->id, $date);
        $job->handle($mockAggregator);

        $this->assertNotNull($mockAggregator->insertedId);

        // Row must still be pending with generation 1
        $row = IngestDirtyDate::find($mockAggregator->insertedId);
        $this->assertNotNull($row);
        $this->assertSame('pending', $row->status);
        $this->assertSame(1, (int) $row->generation);
        $this->assertNull($row->recomputed_at);

        $this->assertTrue(DirtyDateOutbox::hasPendingUpdates($this->branch->id, $date));
        Queue::assertPushed(RecomputeBranchDay::class);
    }

    /**
     * Regression Test 3:
     * Overlapping/duplicate recomputes with fencing: stale aggregate computation
     * must NOT override newer aggregate results.
     */
    public function test_concurrent_duplicate_recomputes_fencing_prevents_stale_aggregate_override(): void
    {
        $date = '2026-10-29';
        $realRebuilder = app(BranchDayAggregator::class);

        // Create Order 1 ($100)
        WhOrder::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'ord-gen-1',
            'source_version' => 1,
            'business_date' => $date,
            'type' => 'dine_in',
            'status' => 'completed',
            'sub_total' => 100.00,
            'discount' => 0.00,
            'items_discount' => 0.00,
            'tax' => 0.00,
            'service' => 0.00,
            'total' => 100.00,
            'cogs' => 20.00,
            'hour_of_day' => 12,
        ]);
        WhPayment::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'order_uuid' => 'ord-gen-1',
            'method' => 'cash',
            'amount' => 100.00,
        ]);

        $batch1 = $this->createBatch();
        DirtyDateOutbox::record($batch1->id, $this->branch, [$date]);

        // Job 1 claims fence token 1
        $fence1 = DirtyDateOutbox::claimRecomputeFence($this->branch->id, $date);
        $snapshot1 = DirtyDateOutbox::getSnapshot($this->branch->id, $date);

        // In parallel, Order 2 ($200) arrives
        WhOrder::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'ord-gen-2',
            'source_version' => 1,
            'business_date' => $date,
            'type' => 'dine_in',
            'status' => 'completed',
            'sub_total' => 200.00,
            'discount' => 0.00,
            'items_discount' => 0.00,
            'tax' => 0.00,
            'service' => 0.00,
            'total' => 200.00,
            'cogs' => 40.00,
            'hour_of_day' => 13,
        ]);
        WhPayment::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'order_uuid' => 'ord-gen-2',
            'method' => 'cash',
            'amount' => 200.00,
        ]);

        $batch2 = $this->createBatch();
        DirtyDateOutbox::record($batch2->id, $this->branch, [$date]);

        // Job 2 claims fence token 2, runs recompute and writes aggregates ($300 total)
        $fence2 = DirtyDateOutbox::claimRecomputeFence($this->branch->id, $date);
        $snapshot2 = DirtyDateOutbox::getSnapshot($this->branch->id, $date);
        $this->assertGreaterThan($fence1, $fence2);

        $realRebuilder->recompute($this->branch->id, $date, $fence2);
        DirtyDateOutbox::markRecomputed($this->branch->id, $date, $snapshot2);

        // Verify agg_branch_daily has $300 gross sales
        $aggRow = DB::table('agg_branch_daily')
            ->where('branch_id', $this->branch->id)
            ->where('business_date', $date)
            ->first();
        $this->assertNotNull($aggRow);
        $this->assertEquals(300.00, (float) $aggRow->gross_sales);

        // Now Job 1 (stale, fence1) tries to commit its stale aggregates.
        // Fencing must prevent stale aggregate from overriding the newer $300 aggregate.
        $this->assertFalse(DirtyDateOutbox::isFenceCurrent($this->branch->id, $date, $fence1));

        // When recompute is called with stale fence1, it must be rejected / not override
        try {
            $realRebuilder->recompute($this->branch->id, $date, $fence1);
            $this->fail('A superseded worker must be explicitly rejected.');
        } catch (FencedWorkerException) {
            // A fenced worker must never acknowledge the dirty-date snapshot.
        }

        $aggRowAfter = DB::table('agg_branch_daily')
            ->where('branch_id', $this->branch->id)
            ->where('business_date', $date)
            ->first();
        $this->assertNotNull($aggRowAfter);
        $this->assertEquals(300.00, (float) $aggRowAfter->gross_sales, 'Stale aggregate must NOT override newer results');
    }

    /**
     * Regression Test 4:
     * Failed enqueue marks outbox row failed, and recovery retries it.
     */
    public function test_failed_enqueue_marks_outbox_failed_and_recovery_recovers(): void
    {
        $batch = $this->createBatch();
        $date = '2026-10-29';

        DirtyDateOutbox::record($batch->id, $this->branch, [$date]);

        // Simulate dispatch failure
        DirtyDateOutbox::markFailed($batch->id, $this->branch->id, $date, 'Queue connection timeout');

        $row = IngestDirtyDate::where('ingest_batch_id', $batch->id)
            ->where('branch_id', $this->branch->id)
            ->where('business_date', $date)
            ->firstOrFail();

        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('Queue connection timeout', (string) $row->last_error);

        // Recovery run
        $aggregator = new class implements BranchDayAggregator
        {
            public int $called = 0;

            public function recompute(int $branchId, string $businessDate): void
            {
                $this->called++;
            }
        };

        $recovered = DirtyDateOutbox::recover(limit: 10, staleCutoff: Carbon::now()->subMinutes(1), sync: true, aggregator: $aggregator);

        $this->assertGreaterThanOrEqual(1, $recovered);
        $this->assertSame(1, $aggregator->called);

        $row->refresh();
        $this->assertSame('processed', $row->status);
        $this->assertNotNull($row->recomputed_at);
    }

    /**
     * Regression Test 5:
     * Transaction rollback reverts dirty date generation and pending row.
     */
    public function test_transaction_rollback_reverts_dirty_date_generation_and_status(): void
    {
        $batch = $this->createBatch();
        $date = '2026-10-29';

        // First successful record
        DirtyDateOutbox::record($batch->id, $this->branch, [$date]);
        $row = IngestDirtyDate::where('ingest_batch_id', $batch->id)->firstOrFail();
        $this->assertSame(1, (int) $row->generation);

        // Second record inside rolled-back transaction
        try {
            DB::transaction(function () use ($batch, $date) {
                DirtyDateOutbox::record($batch->id, $this->branch, [$date]);
                throw new RuntimeException('Simulated warehouse write failure');
            });
        } catch (RuntimeException) {
            // Expected
        }

        $row->refresh();
        // Generation must remain 1, not incremented to 2
        $this->assertSame(1, (int) $row->generation);
    }

    /**
     * Test:
     * Ensure record increments generation atomically for a second document in the same batch.
     */
    public function test_record_increments_generation_atomically_for_same_batch_second_document(): void
    {
        $batch = $this->createBatch();
        $date = '2026-10-29';

        // Document 1 in warehouse transaction
        DB::transaction(function () use ($batch, $date) {
            DirtyDateOutbox::record($batch->id, $this->branch, [$date]);
        });

        $row = IngestDirtyDate::where('ingest_batch_id', $batch->id)->firstOrFail();
        $this->assertSame(1, (int) $row->generation);
        $this->assertSame('pending', $row->status);
        $originalId = $row->id;

        // Document 2 of the same batch in warehouse transaction
        DB::transaction(function () use ($batch, $date) {
            DirtyDateOutbox::record($batch->id, $this->branch, [$date]);
        });

        $row->refresh();
        // ID preserved, generation incremented atomically to 2
        $this->assertSame($originalId, $row->id);
        $this->assertSame(2, (int) $row->generation);
        $this->assertSame('pending', $row->status);
    }

    /**
     * Test:
     * Ensure record increments generation and resets status to pending for an already processed row.
     */
    public function test_record_increments_generation_and_resets_status_for_already_processed_row(): void
    {
        $batch = $this->createBatch();
        $date = '2026-10-29';

        DirtyDateOutbox::record($batch->id, $this->branch, [$date]);

        $snapshot = DirtyDateOutbox::getSnapshot($this->branch->id, $date);
        DirtyDateOutbox::markRecomputed($this->branch->id, $date, $snapshot);

        $row = IngestDirtyDate::where('ingest_batch_id', $batch->id)->firstOrFail();
        $this->assertSame('processed', $row->status);
        $this->assertNotNull($row->recomputed_at);
        $this->assertSame(1, (int) $row->generation);

        // New mutation arrives for the same key
        DB::transaction(function () use ($batch, $date) {
            DirtyDateOutbox::record($batch->id, $this->branch, [$date]);
        });

        $row->refresh();
        $this->assertSame('pending', $row->status);
        $this->assertNull($row->recomputed_at);
        $this->assertSame(2, (int) $row->generation);
        $this->assertTrue(DirtyDateOutbox::hasPendingUpdates($this->branch->id, $date));
    }

    /**
     * Test:
     * Worker crash during recompute does not lose updates, and recover picks it up.
     */
    public function test_worker_crash_during_recompute_does_not_lose_updates_and_is_recovered(): void
    {
        $batch = $this->createBatch();
        $date = '2026-10-29';

        DirtyDateOutbox::record($batch->id, $this->branch, [$date]);

        // Worker simulates crash (e.g. throws exception mid-handle)
        $crashingAggregator = new class implements BranchDayAggregator
        {
            public function recompute(int $branchId, string $businessDate): void
            {
                throw new RuntimeException('Worker killed unexpectedly (OOM/crash)');
            }
        };

        $job = new RecomputeBranchDay($this->branch->id, $date);
        try {
            $job->handle($crashingAggregator);
            $this->fail('Expected crash exception');
        } catch (RuntimeException $e) {
            $this->assertSame('Worker killed unexpectedly (OOM/crash)', $e->getMessage());
        }

        // Row was NEVER marked processed!
        $row = IngestDirtyDate::where('ingest_batch_id', $batch->id)->firstOrFail();
        $this->assertSame('pending', $row->status);
        $this->assertNull($row->recomputed_at);

        // Recovery picks it up
        $workingAggregator = new class implements BranchDayAggregator
        {
            public int $called = 0;

            public function recompute(int $branchId, string $businessDate): void
            {
                $this->called++;
            }
        };

        $recovered = DirtyDateOutbox::recover(10, Carbon::now()->subMinutes(1), sync: true, aggregator: $workingAggregator);
        $this->assertGreaterThanOrEqual(1, $recovered);
        $this->assertSame(1, $workingAggregator->called);

        $row->refresh();
        $this->assertSame('processed', $row->status);
        $this->assertNotNull($row->recomputed_at);
    }

    /**
     * Test:
     * markRecomputed with empty snapshot or mismatched generation clears nothing.
     */
    public function test_mark_recomputed_with_empty_or_mismatched_snapshot_clears_nothing(): void
    {
        $batch = $this->createBatch();
        $date = '2026-10-29';

        DirtyDateOutbox::record($batch->id, $this->branch, [$date]);

        $row = IngestDirtyDate::where('ingest_batch_id', $batch->id)->firstOrFail();
        $this->assertSame('pending', $row->status);
        $this->assertSame(1, (int) $row->generation);

        // Calling markRecomputed with empty array clears nothing
        $cleared = DirtyDateOutbox::markRecomputed($this->branch->id, $date, []);
        $this->assertSame(0, $cleared);
        $row->refresh();
        $this->assertSame('pending', $row->status);

        // Calling markRecomputed with mismatched generation clears nothing
        $clearedMismatched = DirtyDateOutbox::markRecomputed($this->branch->id, $date, [$row->id => 999]);
        $this->assertSame(0, $clearedMismatched);
        $row->refresh();
        $this->assertSame('pending', $row->status);
        $this->assertNull($row->recomputed_at);
    }

    /**
     * Test:
     * Status updates (dispatched, failed) and errors cannot erase later generation.
     */
    public function test_status_updates_and_errors_cannot_erase_later_generation(): void
    {
        $batch = $this->createBatch();
        $date = '2026-10-29';

        // 1. Initial write: generation = 1, status = pending
        DirtyDateOutbox::record($batch->id, $this->branch, [$date]);
        $row = IngestDirtyDate::where('ingest_batch_id', $batch->id)->firstOrFail();
        $this->assertSame(1, (int) $row->generation);
        $this->assertSame('pending', $row->status);

        // 2. Second write bumps generation to 2, status remains pending
        DirtyDateOutbox::record($batch->id, $this->branch, [$date]);
        $row->refresh();
        $this->assertSame(2, (int) $row->generation);
        $this->assertSame('pending', $row->status);

        // 3. Stale worker trying to mark dispatched for generation 1 must not alter generation 2
        DirtyDateOutbox::markDispatched($batch->id, $this->branch->id, $date, generation: 1);
        $row->refresh();
        $this->assertSame('pending', $row->status);
        $this->assertSame(2, (int) $row->generation);

        // 4. Stale worker trying to mark failed for generation 1 must not alter generation 2
        DirtyDateOutbox::markFailed($batch->id, $this->branch->id, $date, 'Stale failure error', generation: 1);
        $row->refresh();
        $this->assertSame('pending', $row->status);
        $this->assertSame(2, (int) $row->generation);
        $this->assertNull($row->last_error);

        // 5. Stale snapshot recovery trying to mark dispatched or failed with generation 1 must not alter generation 2
        DirtyDateOutbox::markSnapshotDispatched([$row->id => 1], $this->branch->id, $date);
        $row->refresh();
        $this->assertSame('pending', $row->status);

        DirtyDateOutbox::markSnapshotFailed([$row->id => 1], $this->branch->id, $date, 'Stale recovery error');
        $row->refresh();
        $this->assertSame('pending', $row->status);
        $this->assertNull($row->last_error);

        // 6. Matching generation 2 successfully updates
        DirtyDateOutbox::markDispatched($batch->id, $this->branch->id, $date, generation: 2);
        $row->refresh();
        $this->assertSame('dispatched', $row->status);
        $this->assertSame(2, (int) $row->generation);
    }

    /**
     * Test:
     * Fenced worker cannot mark batch failed on storage or parsing error.
     */
    public function test_fenced_worker_cannot_mark_batch_failed(): void
    {
        $batch = $this->createBatch();
        $batch->update([
            'status' => 'processing',
            'claim_token' => 'worker-2-token',
            'fencing_token' => 2,
            'lease_expires_at' => now()->addMinutes(5),
            'storage_path' => 'invalid/path.json',
        ]);

        $job = new ProcessIngestBatch($batch->id, [], $this->branch->id);

        // Call protected failBatch with stale fencing token 1
        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('failBatch');
        $method->setAccessible(true);
        $method->invoke($job, $batch->id, 'worker-1-token', 1, 'Simulated storage error by stale worker');

        // Batch status must NOT be 'failed'! It must remain 'processing' owned by worker 2
        $batch->refresh();
        $this->assertSame('processing', $batch->status);
        $this->assertSame('worker-2-token', $batch->claim_token);
        $this->assertSame(2, (int) $batch->fencing_token);
        $this->assertNull($batch->error);
    }

    /**
     * Test:
     * Overlapping recompute where slow worker finishes after newer worker throws FencedWorkerException
     * and rolls back without overwriting newer aggregates.
     */
    public function test_overlapping_recompute_slow_worker_rolls_back_without_corrupting_aggregates(): void
    {
        $date = '2026-10-29';
        $realRebuilder = app(BranchDayAggregator::class);

        // Create initial order ($100)
        WhOrder::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'ord-race-1',
            'source_version' => 1,
            'business_date' => $date,
            'type' => 'dine_in',
            'status' => 'completed',
            'sub_total' => 100.00,
            'discount' => 0.00,
            'items_discount' => 0.00,
            'tax' => 0.00,
            'service' => 0.00,
            'total' => 100.00,
            'cogs' => 20.00,
            'hour_of_day' => 12,
        ]);
        WhPayment::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'order_uuid' => 'ord-race-1',
            'method' => 'cash',
            'amount' => 100.00,
        ]);

        // Worker 1 claims fence token 1
        $fence1 = DirtyDateOutbox::claimRecomputeFence($this->branch->id, $date);

        // Worker 2 claims fence token 2, finishes recomputing ($100 gross sales), and commits fence token 2
        $fence2 = DirtyDateOutbox::claimRecomputeFence($this->branch->id, $date);
        $this->assertGreaterThan($fence1, $fence2);
        $realRebuilder->recompute($this->branch->id, $date, $fence2);

        $aggRow = DB::table('agg_branch_daily')
            ->where('branch_id', $this->branch->id)
            ->where('business_date', $date)
            ->first();
        $this->assertNotNull($aggRow);
        $this->assertEquals(100.00, (float) $aggRow->gross_sales);

        // Now Worker 1 (stale fence token 1) tries to commit:
        // isFenceCurrent is false, so recompute must not modify aggregates
        $this->assertFalse(DirtyDateOutbox::isFenceCurrent($this->branch->id, $date, $fence1));
        try {
            $realRebuilder->recompute($this->branch->id, $date, $fence1);
            $this->fail('A superseded worker must be explicitly rejected.');
        } catch (FencedWorkerException) {
            // A fenced worker must never acknowledge the dirty-date snapshot.
        }

        $aggRowAfter = DB::table('agg_branch_daily')
            ->where('branch_id', $this->branch->id)
            ->where('business_date', $date)
            ->first();
        $this->assertNotNull($aggRowAfter);
        $this->assertEquals(100.00, (float) $aggRowAfter->gross_sales);
    }

    /**
     * Test:
     * Recompute lock prevents overlapping recomputes from executing concurrently.
     */
    public function test_overlapping_recompute_locks_serialize_execution(): void
    {
        $date = '2026-10-29';

        // Worker 1 acquires lock
        $token1 = DirtyDateOutbox::acquireRecomputeLock($this->branch->id, $date, 30);
        $this->assertNotNull($token1);

        // Worker 2 attempts to acquire lock for same branch & date while Worker 1 holds it
        $token2 = DirtyDateOutbox::acquireRecomputeLock($this->branch->id, $date, 30);
        $this->assertNull($token2, 'Second recompute must be blocked by lease lock');

        // Worker 1 finishes and releases lock
        DirtyDateOutbox::releaseRecomputeLock($this->branch->id, $date, $token1);

        // Worker 2 can now acquire lock
        $token2After = DirtyDateOutbox::acquireRecomputeLock($this->branch->id, $date, 30);
        $this->assertNotNull($token2After);
        DirtyDateOutbox::releaseRecomputeLock($this->branch->id, $date, $token2After);
    }
}
