<?php

namespace Tests\Feature\Ingestion\Api;

use App\Domain\Ingestion\Contracts\DocumentHandler;
use App\Domain\Ingestion\Contracts\DocumentHandlerRegistry;
use App\Domain\Ingestion\Contracts\HandleResult;
use App\Domain\Ingestion\Support\DirtyDateOutbox;
use App\Domain\Ingestion\Validation\DocumentRules;
use App\Domain\Metrics\Contracts\BranchDayAggregator;
use App\Enums\BranchStatus;
use App\Jobs\ProcessIngestBatch;
use App\Jobs\RecomputeBranchDay;
use App\Models\Branch;
use App\Models\IngestBatch;
use App\Models\IngestDirtyDate;
use App\Models\Organization;
use App\Models\Restaurant;
use Carbon\Carbon;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use RuntimeException;
use Tests\TestCase;

class DurableRecomputationTestRegistry implements DocumentHandlerRegistry
{
    /** @var list<array{branch_id: int, uuid: string, type: string}> */
    public array $calls = [];

    /** @var array<string, int> */
    public array $callCountsByUuid = [];

    /** @var ?Closure(string, Branch, array<string, mixed>): void */
    public ?Closure $beforeHandle = null;

    /** @var ?Closure(string, Branch, array<string, mixed>): ?HandleResult */
    public ?Closure $customHandle = null;

    /**
     * @param  ?Closure(string $uuid, Branch $branch, array<string, mixed> $doc): void  $beforeHandle
     * @param  ?Closure(string $uuid, Branch $branch, array<string, mixed> $doc): ?HandleResult  $customHandle
     */
    public function __construct(
        ?Closure $beforeHandle = null,
        ?Closure $customHandle = null,
    ) {
        $this->beforeHandle = $beforeHandle;
        $this->customHandle = $customHandle;
    }

    public function for(string $type): DocumentHandler
    {
        return new class($this, $type) implements DocumentHandler
        {
            public function __construct(
                private DurableRecomputationTestRegistry $registry,
                private string $type,
            ) {}

            public function type(): string
            {
                return $this->type;
            }

            public function handle(Branch $branch, array $document): HandleResult
            {
                $uuid = (string) $document['uuid'];
                $this->registry->calls[] = [
                    'branch_id' => $branch->id,
                    'uuid' => $uuid,
                    'type' => $this->type,
                ];
                $this->registry->callCountsByUuid[$uuid] = ($this->registry->callCountsByUuid[$uuid] ?? 0) + 1;

                if ($this->registry->beforeHandle !== null) {
                    ($this->registry->beforeHandle)($uuid, $branch, $document);
                }

                if ($this->registry->customHandle !== null) {
                    $customRes = ($this->registry->customHandle)($uuid, $branch, $document);
                    if ($customRes !== null) {
                        return $customRes;
                    }
                }

                $date = (string) ($document['data']['business_date'] ?? '2026-10-29');

                return HandleResult::applied([$date]);
            }
        };
    }

    public function types(): array
    {
        return DocumentRules::TYPES;
    }
}

class DurableAggregateRecomputationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-29 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createBranch(array $attributes = []): Branch
    {
        $org = Organization::factory()->create();
        $restaurant = Restaurant::factory()->create(['organization_id' => $org->id]);

        return Branch::factory()->create(array_merge([
            'organization_id' => $org->id,
            'restaurant_id' => $restaurant->id,
            'status' => BranchStatus::ACTIVE,
            'pos_version' => '1.0.0',
        ], $attributes));
    }

    /**
     * @param  list<array<string, mixed>>  $documents
     * @param  array<string, mixed>  $batchAttributes
     */
    protected function createBatch(Branch $branch, array $documents, array $batchAttributes = []): IngestBatch
    {
        $batchUuid = (string) Str::uuid();
        $envelope = [
            'batch_uuid' => $batchUuid,
            'schema_version' => 1,
            'pos_version' => '1.0.0',
            'seq_from' => 1,
            'seq_to' => count($documents),
            'documents' => $documents,
        ];

        $storagePath = "ingest-test/{$batchUuid}.json";
        Storage::disk('local')->put($storagePath, (string) json_encode($envelope));

        return IngestBatch::create(array_merge([
            'organization_id' => $branch->organization_id,
            'branch_id' => $branch->id,
            'batch_uuid' => $batchUuid,
            'schema_version' => 1,
            'pos_version' => '1.0.0',
            'seq_from' => 1,
            'seq_to' => count($documents),
            'docs_count' => count($documents),
            'accepted_count' => count($documents),
            'rejected_count' => 0,
            'status' => 'received',
            'result' => [
                'accepted' => array_column($documents, 'uuid'),
                'rejected' => [],
                '_accepted_indices' => range(0, count($documents) - 1),
            ],
            'storage_path' => $storagePath,
            'received_at' => now(),
        ], $batchAttributes));
    }

    /**
     * 1. Realistic regression test: Worker crashes immediately after handler commit before enqueue.
     * On retry/recovery replay, handler version check skips previously applied doc.
     * Verify dirty date from the first run is NOT lost and is dispatched.
     */
    public function test_crash_immediately_after_handler_commit_before_enqueue_preserves_dirty_dates(): void
    {
        Queue::fake([RecomputeBranchDay::class]);

        $branch = $this->createBranch();
        $doc1Uuid = (string) Str::uuid();
        $doc2Uuid = (string) Str::uuid();

        $batch = $this->createBatch($branch, [
            [
                'type' => 'order',
                'uuid' => $doc1Uuid,
                'version' => 1,
                'op' => 'upsert',
                'occurred_at' => now()->toIso8601String(),
                'data' => ['business_date' => '2026-10-29', 'total' => '100.00'],
            ],
            [
                'type' => 'order',
                'uuid' => $doc2Uuid,
                'version' => 1,
                'op' => 'upsert',
                'occurred_at' => now()->toIso8601String(),
                'data' => ['business_date' => '2026-10-30', 'total' => '200.00'],
            ],
        ]);

        // Run 1: Worker processes Doc 1, but crashes immediately after Doc 1 commits!
        $registry1 = new DurableRecomputationTestRegistry;
        ProcessIngestBatch::$afterDocumentCommitted = function (array $doc) use ($doc1Uuid): void {
            if ($doc['uuid'] === $doc1Uuid) {
                throw new RuntimeException('Simulated crash immediately after doc 1 commit before enqueue');
            }
        };

        $worker1 = new ProcessIngestBatch($batch->id, [$doc1Uuid, $doc2Uuid], $branch->id, [0, 1]);

        try {
            $worker1->handle($registry1);
            $this->fail('Expected crash exception was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated crash immediately after doc 1 commit before enqueue', $e->getMessage());
        } finally {
            ProcessIngestBatch::$afterDocumentCommitted = null;
        }

        // Verify outbox entry for doc 1 on 2026-10-29 was committed atomically into DB!
        $this->assertDatabaseHas('ingest_dirty_dates', [
            'ingest_batch_id' => $batch->id,
            'branch_id' => $branch->id,
            'business_date' => '2026-10-29',
            'status' => 'pending',
        ]);

        // No RecomputeBranchDay was queued yet because batch crashed
        Queue::assertNothingPushed();

        // Expire lease to simulate recovery after worker death
        $batch->refresh();
        $batch->update([
            'status' => 'processing',
            'lease_expires_at' => now()->subSeconds(30),
            'updated_at' => now()->subSeconds(60),
        ]);

        // Run 2: Recovery / second worker executes the batch.
        // Doc 1 was applied in Run 1: simulated version guard returns skipped for Doc 1!
        $registry2 = new DurableRecomputationTestRegistry(
            customHandle: function (string $uuid): ?HandleResult {
                // Return skipped for doc 1 simulating existing source_version >= version
                static $ranOnce = false;
                if (! $ranOnce) {
                    $ranOnce = true;

                    return HandleResult::skipped();
                }

                return null; // Doc 2 uses default applied ['2026-10-30']
            }
        );

        $worker2 = new ProcessIngestBatch($batch->id, [$doc1Uuid, $doc2Uuid], $branch->id, [0, 1]);
        $worker2->handle($registry2);

        $batch->refresh();
        $this->assertSame('processed', $batch->status);

        // Both dirty dates must exist in outbox!
        $this->assertDatabaseHas('ingest_dirty_dates', [
            'ingest_batch_id' => $batch->id,
            'branch_id' => $branch->id,
            'business_date' => '2026-10-29',
        ]);
        $this->assertDatabaseHas('ingest_dirty_dates', [
            'ingest_batch_id' => $batch->id,
            'branch_id' => $branch->id,
            'business_date' => '2026-10-30',
        ]);

        // Verify RecomputeBranchDay was dispatched for BOTH dates!
        // 2026-10-29 from Doc 1 was NOT lost even though Doc 1 was skipped on replay!
        Queue::assertPushed(RecomputeBranchDay::class, 2);
        Queue::assertPushed(RecomputeBranchDay::class, function (RecomputeBranchDay $job) use ($branch): bool {
            return $job->branchId === $branch->id && $job->businessDate === '2026-10-29';
        });
        Queue::assertPushed(RecomputeBranchDay::class, function (RecomputeBranchDay $job) use ($branch): bool {
            return $job->branchId === $branch->id && $job->businessDate === '2026-10-30';
        });
    }

    /**
     * 2. Realistic regression test: Failure after batch completion before enqueue.
     * Batch is marked status = 'processed', but worker dies before RecomputeBranchDay dispatch.
     * Scheduled recovery MUST find the pending dirty date and dispatch it.
     */
    public function test_failure_after_batch_completion_before_enqueue_recovered_by_scheduled_path(): void
    {
        Queue::fake([RecomputeBranchDay::class]);

        $branch = $this->createBranch();
        $docUuid = (string) Str::uuid();

        // Create batch that completed (status = 'processed')
        $batch = $this->createBatch($branch, [
            [
                'type' => 'order',
                'uuid' => $docUuid,
                'version' => 1,
                'op' => 'upsert',
                'occurred_at' => now()->toIso8601String(),
                'data' => ['business_date' => '2026-10-29'],
            ],
        ], [
            'status' => 'processed',
            'processed_at' => now(),
        ]);

        // During doc handling, outbox row was committed, but worker died before dispatching
        DB::table('ingest_dirty_dates')->insert([
            'organization_id' => $branch->organization_id,
            'branch_id' => $branch->id,
            'ingest_batch_id' => $batch->id,
            'business_date' => '2026-10-29',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // No aggregate job queued yet
        Queue::assertNothingPushed();

        // Run recovery command (scheduled recovery)
        $exitCode = Artisan::call('ingest:recover');
        $this->assertSame(0, $exitCode);

        // Verify recovery dispatched RecomputeBranchDay even though batch is already 'processed'!
        Queue::assertPushed(RecomputeBranchDay::class, 1);
        Queue::assertPushed(RecomputeBranchDay::class, function (RecomputeBranchDay $job) use ($branch): bool {
            return $job->branchId === $branch->id && $job->businessDate === '2026-10-29';
        });

        // Verify outbox entry is now marked dispatched
        $this->assertDatabaseHas('ingest_dirty_dates', [
            'ingest_batch_id' => $batch->id,
            'business_date' => '2026-10-29',
            'status' => 'dispatched',
        ]);
    }

    /**
     * 3. Realistic regression test: Queue dispatch failure.
     * Dispatching to queue broker throws an exception. Outbox entry is marked failed.
     * Recovery command later retries dispatch successfully when broker is available.
     */
    public function test_queue_dispatch_failure_and_safe_recovery_retry(): void
    {
        $branch = $this->createBranch();
        $docUuid = (string) Str::uuid();

        $batch = $this->createBatch($branch, [
            [
                'type' => 'order',
                'uuid' => $docUuid,
                'version' => 1,
                'op' => 'upsert',
                'occurred_at' => now()->toIso8601String(),
                'data' => ['business_date' => '2026-10-29'],
            ],
        ]);

        // Simulate queue broker down by mocking Queue facade
        Queue::shouldReceive('connection')->andThrow(new RuntimeException('Queue broker connection timed out'));

        $registry = new DurableRecomputationTestRegistry;
        $worker = new ProcessIngestBatch($batch->id, [$docUuid], $branch->id, [0]);
        $worker->handle($registry);

        $batch->refresh();
        $this->assertSame('processed', $batch->status);

        // Outbox record must be marked 'failed' with error recorded, NOT 'processed'
        $outbox = IngestDirtyDate::where('ingest_batch_id', $batch->id)->firstOrFail();
        $this->assertSame('failed', $outbox->status);
        $this->assertStringContainsString('Queue broker connection timed out', (string) $outbox->last_error);

        // Now queue broker is recovered
        Queue::swap(new QueueFake(app()));

        // Run recovery command to retry the failed outbox dirty date
        $exitCode = Artisan::call('ingest:recover');
        $this->assertSame(0, $exitCode);

        // Verify job was dispatched upon recovery
        Queue::assertPushed(RecomputeBranchDay::class, 1);
        Queue::assertPushed(RecomputeBranchDay::class, function (RecomputeBranchDay $job) use ($branch): bool {
            return $job->branchId === $branch->id && $job->businessDate === '2026-10-29';
        });

        $outbox->refresh();
        $this->assertSame('dispatched', $outbox->status);
    }

    /**
     * 4. Realistic regression test: Concurrent repeated recovery.
     * Multiple concurrent recovery calls on same pending dirty dates must not deadlock
     * or cause duplicate dispatches.
     */
    public function test_concurrent_repeated_recovery_prevents_duplicate_dispatches(): void
    {
        Queue::fake([RecomputeBranchDay::class]);

        $branch = $this->createBranch();
        $batch = $this->createBatch($branch, [], ['status' => 'processed']);

        // Insert pending dirty dates for 3 distinct dates
        foreach (['2026-10-27', '2026-10-28', '2026-10-29'] as $date) {
            DB::table('ingest_dirty_dates')->insert([
                'organization_id' => $branch->organization_id,
                'branch_id' => $branch->id,
                'ingest_batch_id' => $batch->id,
                'business_date' => $date,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $staleCutoff = now()->subSeconds(60);

        // Runner 1 executes recovery
        $count1 = DirtyDateOutbox::recover(limit: 100, staleCutoff: $staleCutoff);

        // Runner 2 executes recovery immediately after/concurrently
        $count2 = DirtyDateOutbox::recover(limit: 100, staleCutoff: $staleCutoff);

        $this->assertSame(3, $count1);
        $this->assertSame(0, $count2); // Runner 2 did NOT duplicate any dispatches!

        // Total dispatched jobs in queue is exactly 3 (one per date)
        Queue::assertPushed(RecomputeBranchDay::class, 3);
    }

    /**
     * 5. Concurrency test: Updates arriving during recomputation.
     * Watermark ID ensures an update committed while recompute is running is NOT marked processed,
     * and triggers a follow-up recomputation so no updates are lost.
     */
    public function test_updates_arriving_during_recomputation_are_not_lost(): void
    {
        Queue::fake([RecomputeBranchDay::class]);

        $branch = $this->createBranch();
        $batch1 = $this->createBatch($branch, [], ['status' => 'processed']);
        $batch2 = $this->createBatch($branch, [], ['status' => 'processed']);

        // Batch 1 records dirty date (outbox row ID 1)
        DB::table('ingest_dirty_dates')->insert([
            'id' => 1,
            'organization_id' => $branch->organization_id,
            'branch_id' => $branch->id,
            'ingest_batch_id' => $batch1->id,
            'business_date' => '2026-10-29',
            'status' => 'dispatched',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Fake aggregator: during recompute, Batch 2 commits a new update for the same (branch, date)!
        $aggregator = new class($branch, $batch2) implements BranchDayAggregator
        {
            public int $recomputeCount = 0;

            public function __construct(private Branch $branch, private IngestBatch $batch2) {}

            public function recompute(int $branchId, string $businessDate): void
            {
                $this->recomputeCount++;

                // Simulate new document committed during recompute:
                // Creates outbox row ID 2 with status 'pending'
                DB::table('ingest_dirty_dates')->insert([
                    'id' => 2,
                    'organization_id' => $this->branch->organization_id,
                    'branch_id' => $branchId,
                    'ingest_batch_id' => $this->batch2->id,
                    'business_date' => $businessDate,
                    'status' => 'pending',
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            }
        };

        // Execute RecomputeBranchDay
        $job = new RecomputeBranchDay($branch->id, '2026-10-29');
        $job->handle($aggregator);

        $this->assertSame(1, $aggregator->recomputeCount);

        // Assert: Row ID 1 is marked processed
        $row1 = IngestDirtyDate::find(1);
        $this->assertNotNull($row1);
        $this->assertSame('processed', $row1->status);
        $this->assertNotNull($row1->recomputed_at);

        // Assert: Row ID 2 (which arrived during recompute) is NOT marked processed!
        $row2 = IngestDirtyDate::find(2);
        $this->assertNotNull($row2);
        $this->assertSame('pending', $row2->status);
        $this->assertNull($row2->recomputed_at);

        // Assert: Follow-up RecomputeBranchDay was dispatched to process the update that arrived during recompute!
        Queue::assertPushed(RecomputeBranchDay::class, function (RecomputeBranchDay $pushed) use ($branch): bool {
            return $pushed->branchId === $branch->id && $pushed->businessDate === '2026-10-29';
        });
    }

    /**
     * 6. Regression test: Both old and new dates are persisted to outbox and dispatched.
     */
    public function test_old_and_new_dates_both_persisted_and_dispatched(): void
    {
        Queue::fake([RecomputeBranchDay::class]);

        $branch = $this->createBranch();
        $docUuid = (string) Str::uuid();

        $batch = $this->createBatch($branch, [
            [
                'type' => 'order',
                'uuid' => $docUuid,
                'version' => 2,
                'op' => 'upsert',
                'occurred_at' => now()->toIso8601String(),
                'data' => ['business_date' => '2026-10-29'],
            ],
        ]);

        // Handler returns both old date (2026-10-28) and new date (2026-10-29)
        $registry = new DurableRecomputationTestRegistry(
            customHandle: fn (): HandleResult => HandleResult::applied(['2026-10-28', '2026-10-29'])
        );

        $worker = new ProcessIngestBatch($batch->id, [$docUuid], $branch->id, [0]);
        $worker->handle($registry);

        // Both dates must be in outbox
        $this->assertDatabaseHas('ingest_dirty_dates', [
            'ingest_batch_id' => $batch->id,
            'business_date' => '2026-10-28',
        ]);
        $this->assertDatabaseHas('ingest_dirty_dates', [
            'ingest_batch_id' => $batch->id,
            'business_date' => '2026-10-29',
        ]);

        // Both dates must be dispatched
        Queue::assertPushed(RecomputeBranchDay::class, 2);
        Queue::assertPushed(RecomputeBranchDay::class, fn (RecomputeBranchDay $j) => $j->businessDate === '2026-10-28');
        Queue::assertPushed(RecomputeBranchDay::class, fn (RecomputeBranchDay $j) => $j->businessDate === '2026-10-29');
    }

    /**
     * 7. Stale dispatched aggregate job (worker crash / failed queue job) is recovered.
     */
    public function test_stale_dispatched_job_is_recovered(): void
    {
        Queue::fake([RecomputeBranchDay::class]);

        $branch = $this->createBranch();
        $batch = $this->createBatch($branch, [], ['status' => 'processed']);

        // An aggregate job was dispatched 10 minutes ago, but never finished (worker died)
        DB::table('ingest_dirty_dates')->insert([
            'organization_id' => $branch->organization_id,
            'branch_id' => $branch->id,
            'ingest_batch_id' => $batch->id,
            'business_date' => '2026-10-29',
            'status' => 'dispatched',
            'dispatched_at' => now()->subMinutes(10),
            'dispatch_attempts' => 1,
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);

        $exitCode = Artisan::call('ingest:recover', ['--stale-seconds' => 120]);
        $this->assertSame(0, $exitCode);

        // Re-dispatched!
        Queue::assertPushed(RecomputeBranchDay::class, 1);
        Queue::assertPushed(RecomputeBranchDay::class, fn (RecomputeBranchDay $j) => $j->businessDate === '2026-10-29');
    }

    /**
     * 8. Synchronous recovery option (--sync) performs recomputation directly.
     */
    public function test_sync_recovery_option_performs_recomputation_directly(): void
    {
        $branch = $this->createBranch();
        $batch = $this->createBatch($branch, [], ['status' => 'processed']);

        DB::table('ingest_dirty_dates')->insert([
            'organization_id' => $branch->organization_id,
            'branch_id' => $branch->id,
            'ingest_batch_id' => $batch->id,
            'business_date' => '2026-10-29',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mockAggregator = new class implements BranchDayAggregator
        {
            public bool $called = false;

            public function recompute(int $branchId, string $businessDate): void
            {
                $this->called = true;
            }
        };

        $this->app->instance(BranchDayAggregator::class, $mockAggregator);

        $exitCode = Artisan::call('ingest:recover', ['--sync' => true]);
        $this->assertSame(0, $exitCode);
        $this->assertTrue($mockAggregator->called);

        // Outbox is marked processed
        $this->assertDatabaseHas('ingest_dirty_dates', [
            'ingest_batch_id' => $batch->id,
            'business_date' => '2026-10-29',
            'status' => 'processed',
        ]);
    }
}
