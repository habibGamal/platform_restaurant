<?php

namespace Tests\Feature\Ingestion\Api;

use App\Domain\Ingestion\Contracts\DocumentHandler;
use App\Domain\Ingestion\Contracts\DocumentHandlerRegistry;
use App\Domain\Ingestion\Contracts\HandleResult;
use App\Domain\Ingestion\Validation\DocumentRules;
use App\Enums\BranchStatus;
use App\Jobs\ProcessIngestBatch;
use App\Jobs\RecomputeBranchDay;
use App\Models\Branch;
use App\Models\IngestBatch;
use App\Models\Organization;
use App\Models\Restaurant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CountingRecoveryTestRegistry implements DocumentHandlerRegistry
{
    /** @var list<array{branch_id: int, uuid: string, type: string}> */
    public array $calls = [];

    /** @var array<string, int> */
    public array $callCountsByUuid = [];

    /** @var ?\Closure(string, Branch, array<string, mixed>): void */
    public ?\Closure $beforeHandle = null;

    /**
     * @param  ?\Closure(string $uuid, Branch $branch, array<string, mixed> $doc): void  $beforeHandle
     */
    public function __construct(private ?string $failingUuid = null, ?\Closure $beforeHandle = null)
    {
        $this->beforeHandle = $beforeHandle;
    }

    public function for(string $type): DocumentHandler
    {
        return new class($this, $type, $this->failingUuid) implements DocumentHandler
        {
            public function __construct(
                private CountingRecoveryTestRegistry $registry,
                private string $type,
                private ?string $failingUuid = null,
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

                if ($this->failingUuid !== null && $uuid === $this->failingUuid) {
                    throw new RuntimeException("Simulated per-document failure for doc: {$uuid}");
                }

                return HandleResult::applied(['2026-10-29']);
            }
        };
    }

    public function types(): array
    {
        return DocumentRules::TYPES;
    }
}

class IngestRecoveryTest extends TestCase
{
    use RefreshDatabase;

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
        ], $attributes));
    }

    /**
     * @param  list<array<string, mixed>>  $documents
     * @param  array<string, mixed>  $batchAttributes
     * @return array{0: IngestBatch, 1: string}
     */
    protected function createStoredBatch(Branch $branch, array $documents, array $batchAttributes = []): array
    {
        $batchUuid = (string) Str::uuid();
        $envelope = [
            'schema_version' => 1,
            'batch_uuid' => $batchUuid,
            'branch_uuid' => $branch->uuid,
            'pos_version' => '2.4.0',
            'sent_at' => now()->toIso8601String(),
            'seq_from' => 1,
            'seq_to' => count($documents),
            'documents' => $documents,
        ];

        $storagePath = "ingest/{$branch->organization_id}/{$branch->id}/2026/10/{$batchUuid}.json";
        $json = json_encode($envelope);
        $this->assertIsString($json);
        Storage::disk('local')->put($storagePath, $json);

        $acceptedUuids = array_map(fn ($d) => (string) $d['uuid'], $documents);
        $acceptedIndices = array_keys($documents);

        $batch = IngestBatch::create(array_merge([
            'organization_id' => $branch->organization_id,
            'branch_id' => $branch->id,
            'batch_uuid' => $batchUuid,
            'schema_version' => 1,
            'pos_version' => '2.4.0',
            'seq_from' => 1,
            'seq_to' => count($documents),
            'docs_count' => count($documents),
            'accepted_count' => count($documents),
            'rejected_count' => 0,
            'status' => 'received',
            'result' => [
                'batch_uuid' => $batchUuid,
                'accepted' => $acceptedUuids,
                'rejected' => [],
                '_accepted_indices' => $acceptedIndices,
            ],
            'storage_path' => $storagePath,
            'received_at' => now(),
        ], $batchAttributes));

        return [$batch, $storagePath];
    }

    public function test_duplicate_receipt_redispatches_uncompleted_batch_in_processing_status(): void
    {
        Storage::fake('local');
        Queue::fake();

        $branch = $this->createBranch(['status' => BranchStatus::ACTIVE]);
        $token = $branch->issueIngestToken();

        $batchUuid = (string) Str::uuid();
        $docUuid = (string) Str::uuid();

        $envelope = [
            'schema_version' => 1,
            'batch_uuid' => $batchUuid,
            'branch_uuid' => $branch->uuid,
            'pos_version' => '2.4.0',
            'sent_at' => now()->toIso8601String(),
            'seq_from' => 1,
            'seq_to' => 1,
            'documents' => [
                [
                    'type' => 'category',
                    'uuid' => $docUuid,
                    'version' => 1,
                    'op' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'data' => ['name' => 'Hot Drinks'],
                ],
            ],
        ];

        // 1. Initial post
        $response1 = $this->withToken($token)->postJson(route('api.v1.ingest.batches'), $envelope);
        $response1->assertStatus(202)
            ->assertJson([
                'batch_uuid' => $batchUuid,
                'accepted' => [$docUuid],
            ]);

        $batch = IngestBatch::where('batch_uuid', $batchUuid)->firstOrFail();

        // 2. Simulate worker claimed the batch but was terminated / died: status becomes 'processing'
        $batch->update([
            'status' => 'processing',
            'updated_at' => now()->subSeconds(10),
        ]);

        // Clear queue fake assertions
        Queue::fake();

        // 3. POS client retries sending identical batch envelope (duplicate receipt)
        $response2 = $this->withToken($token)->postJson(route('api.v1.ingest.batches'), $envelope);

        // Must return 409 retaining public response contract
        $response2->assertStatus(409)
            ->assertJson([
                'batch_uuid' => $batchUuid,
                'accepted' => [$docUuid],
                'rejected' => [],
            ]);

        // CRITICAL: Must redispatch ProcessIngestBatch for the processing batch so work is NOT lost permanently!
        Queue::assertPushed(ProcessIngestBatch::class, function (ProcessIngestBatch $job) use ($batch, $docUuid) {
            return $job->batchId === $batch->id
                && $job->acceptedUuids === [$docUuid]
                && $job->acceptedIndices === [0];
        });
    }

    public function test_concurrent_workers_do_not_process_same_batch_simultaneously(): void
    {
        Storage::fake('local');
        Queue::fake();

        $branch = $this->createBranch(['status' => BranchStatus::ACTIVE]);
        $doc1Uuid = (string) Str::uuid();

        [$batch] = $this->createStoredBatch($branch, [
            [
                'type' => 'category',
                'uuid' => $doc1Uuid,
                'version' => 1,
                'op' => 'upsert',
                'occurred_at' => now()->toIso8601String(),
                'data' => ['name' => 'Seafood'],
            ],
        ]);

        $registry = new CountingRecoveryTestRegistry;
        $this->app->instance(DocumentHandlerRegistry::class, $registry);

        // Worker 1 simulates active claim (status = processing, updated_at = now())
        $batch->update([
            'status' => 'processing',
            'updated_at' => now(),
        ]);

        // Worker 2 attempts to process the same batch concurrently while Worker 1 has an active fresh claim
        $worker2Job = new ProcessIngestBatch($batch->id, [$doc1Uuid], $branch->id, [0]);
        $worker2Job->handle($registry);

        // Worker 2 must NOT process documents because Worker 1 holds the live active claim!
        $this->assertCount(0, $registry->calls);
        $this->assertSame('processing', $batch->fresh()->status);
    }

    public function test_crash_after_claim_recovers_stale_claim_and_processes_accepted_docs(): void
    {
        Storage::fake('local');
        Queue::fake();

        $branch = $this->createBranch(['status' => BranchStatus::ACTIVE]);
        $doc1Uuid = (string) Str::uuid();
        $doc2Uuid = (string) Str::uuid();

        [$batch] = $this->createStoredBatch($branch, [
            [
                'type' => 'category',
                'uuid' => $doc1Uuid,
                'version' => 1,
                'op' => 'upsert',
                'occurred_at' => now()->toIso8601String(),
                'data' => ['name' => 'First'],
            ],
            [
                'type' => 'category',
                'uuid' => $doc2Uuid,
                'version' => 2,
                'op' => 'upsert',
                'occurred_at' => now()->toIso8601String(),
                'data' => ['name' => 'Second'],
            ],
        ], [
            // Stale claim: worker crashed after claiming 100 seconds ago
            'status' => 'processing',
            'updated_at' => now()->subSeconds(100),
        ]);

        $registry = new CountingRecoveryTestRegistry;
        $this->app->instance(DocumentHandlerRegistry::class, $registry);

        // Retry / recovery job runs
        $recoveryJob = new ProcessIngestBatch($batch->id, [$doc1Uuid, $doc2Uuid], $branch->id, [0, 1]);
        $recoveryJob->handle($registry);

        // Documents must be handled in order
        $this->assertCount(2, $registry->calls);
        $this->assertSame($doc1Uuid, $registry->calls[0]['uuid']);
        $this->assertSame($doc2Uuid, $registry->calls[1]['uuid']);

        // Batch finishes processed
        $batch->refresh();
        $this->assertSame('processed', $batch->status);
        $this->assertNotNull($batch->processed_at);

        // RecomputeBranchDay dispatched
        Queue::assertPushed(RecomputeBranchDay::class, 1);
    }

    public function test_completed_batch_is_never_reprocessed_by_retry_duplicate_or_recovery(): void
    {
        Storage::fake('local');
        Queue::fake();

        $branch = $this->createBranch(['status' => BranchStatus::ACTIVE]);
        $token = $branch->issueIngestToken();
        $docUuid = (string) Str::uuid();

        [$batch] = $this->createStoredBatch($branch, [
            [
                'type' => 'category',
                'uuid' => $docUuid,
                'version' => 1,
                'op' => 'upsert',
                'occurred_at' => now()->toIso8601String(),
                'data' => ['name' => 'Completed Category'],
            ],
        ], [
            'status' => 'processed',
            'processed_at' => now()->subMinutes(10),
        ]);

        $registry = new CountingRecoveryTestRegistry;
        $this->app->instance(DocumentHandlerRegistry::class, $registry);

        // 1. Direct job execution on processed batch must do nothing
        $job = new ProcessIngestBatch($batch->id, [$docUuid], $branch->id, [0]);
        $job->handle($registry);

        $this->assertCount(0, $registry->calls);

        // 2. Duplicate receipt from POS for processed batch returns 409 but NEVER redispatches
        $envelope = [
            'schema_version' => 1,
            'batch_uuid' => $batch->batch_uuid,
            'branch_uuid' => $branch->uuid,
            'pos_version' => '2.4.0',
            'sent_at' => now()->toIso8601String(),
            'seq_from' => 1,
            'seq_to' => 1,
            'documents' => [
                [
                    'type' => 'category',
                    'uuid' => $docUuid,
                    'version' => 1,
                    'op' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'data' => ['name' => 'Completed Category'],
                ],
            ],
        ];

        $response = $this->withToken($token)->postJson(route('api.v1.ingest.batches'), $envelope);
        $response->assertStatus(409);

        // MUST NOT be pushed!
        Queue::assertNothingPushed();
    }

    public function test_scheduled_recovery_command_recovers_uncompleted_batches_without_pos_resending(): void
    {
        Storage::fake('local');
        Queue::fake();

        $branch = $this->createBranch(['status' => BranchStatus::ACTIVE]);
        $doc1Uuid = (string) Str::uuid();
        $doc2Uuid = (string) Str::uuid();
        $doc3Uuid = (string) Str::uuid();
        $doc4Uuid = (string) Str::uuid();

        // 1. Batch in 'received' that was never processed (e.g. queue broker crash before enqueue)
        [$receivedBatch] = $this->createStoredBatch($branch, [
            [
                'type' => 'category',
                'uuid' => $doc1Uuid,
                'version' => 1,
                'op' => 'upsert',
                'occurred_at' => now()->toIso8601String(),
                'data' => ['name' => 'Cat 1'],
            ],
        ], [
            'status' => 'received',
            'created_at' => now()->subMinutes(5),
            'received_at' => now()->subMinutes(5),
        ]);

        // 2. Batch in 'processing' with stale claim (> 90s)
        [$staleProcessingBatch] = $this->createStoredBatch($branch, [
            [
                'type' => 'category',
                'uuid' => $doc2Uuid,
                'version' => 2,
                'op' => 'upsert',
                'occurred_at' => now()->toIso8601String(),
                'data' => ['name' => 'Cat 2'],
            ],
        ], [
            'status' => 'processing',
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subSeconds(120),
        ]);

        // 3. Batch in 'processing' with FRESH claim (< 90s, live work) - MUST NOT BE RECOVERED
        [$liveBatch] = $this->createStoredBatch($branch, [
            [
                'type' => 'category',
                'uuid' => $doc3Uuid,
                'version' => 3,
                'op' => 'upsert',
                'occurred_at' => now()->toIso8601String(),
                'data' => ['name' => 'Cat 3'],
            ],
        ], [
            'status' => 'processing',
            'created_at' => now()->subSeconds(10),
            'updated_at' => now()->subSeconds(10),
        ]);

        // 4. Batch in 'processed' - MUST NOT BE RECOVERED
        [$processedBatch] = $this->createStoredBatch($branch, [
            [
                'type' => 'category',
                'uuid' => $doc4Uuid,
                'version' => 4,
                'op' => 'upsert',
                'occurred_at' => now()->toIso8601String(),
                'data' => ['name' => 'Cat 4'],
            ],
        ], [
            'status' => 'processed',
            'processed_at' => now()->subMinutes(1),
        ]);

        // Run recovery command
        $exitCode = Artisan::call('ingest:recover');
        $this->assertSame(0, $exitCode);

        // Assert: receivedBatch and staleProcessingBatch were pushed
        Queue::assertPushed(ProcessIngestBatch::class, function (ProcessIngestBatch $job) use ($receivedBatch) {
            return $job->batchId === $receivedBatch->id;
        });

        Queue::assertPushed(ProcessIngestBatch::class, function (ProcessIngestBatch $job) use ($staleProcessingBatch) {
            return $job->batchId === $staleProcessingBatch->id;
        });

        // Assert: liveBatch and processedBatch were NEVER pushed
        Queue::assertNotPushed(ProcessIngestBatch::class, function (ProcessIngestBatch $job) use ($liveBatch) {
            return $job->batchId === $liveBatch->id;
        });

        Queue::assertNotPushed(ProcessIngestBatch::class, function (ProcessIngestBatch $job) use ($processedBatch) {
            return $job->batchId === $processedBatch->id;
        });
    }

    public function test_retry_after_claim_with_per_document_failure_preserves_rejection_and_accepted_docs(): void
    {
        Storage::fake('local');
        Queue::fake();

        $branch = $this->createBranch(['status' => BranchStatus::ACTIVE]);
        $doc1Uuid = (string) Str::uuid();
        $doc2Uuid = (string) Str::uuid();
        $doc3Uuid = (string) Str::uuid();

        [$batch] = $this->createStoredBatch($branch, [
            [
                'type' => 'category',
                'uuid' => $doc1Uuid,
                'version' => 1,
                'op' => 'upsert',
                'occurred_at' => now()->toIso8601String(),
                'data' => ['name' => 'Valid Doc 1'],
            ],
            [
                'type' => 'category',
                'uuid' => $doc2Uuid,
                'version' => 2,
                'op' => 'upsert',
                'occurred_at' => now()->toIso8601String(),
                'data' => ['name' => 'Failing Doc 2'],
            ],
            [
                'type' => 'category',
                'uuid' => $doc3Uuid,
                'version' => 3,
                'op' => 'upsert',
                'occurred_at' => now()->toIso8601String(),
                'data' => ['name' => 'Valid Doc 3'],
            ],
        ], [
            'status' => 'processing',
            'updated_at' => now()->subSeconds(100),
        ]);

        $registry = new CountingRecoveryTestRegistry($doc2Uuid);
        $this->app->instance(DocumentHandlerRegistry::class, $registry);

        $job = new ProcessIngestBatch($batch->id, [$doc1Uuid, $doc2Uuid, $doc3Uuid], $branch->id, [0, 1, 2]);
        $job->handle($registry);

        // Doc 1 and Doc 3 are applied
        $this->assertCount(3, $registry->calls);

        // Doc 2 rejection row created
        $this->assertDatabaseHas('ingest_rejections', [
            'ingest_batch_id' => $batch->id,
            'branch_id' => $branch->id,
            'doc_uuid' => $doc2Uuid,
        ]);

        // Batch finishes processed without data loss of doc 1 and 3
        $batch->refresh();
        $this->assertSame('processed', $batch->status);
    }

    public function test_active_worker_exceeding_stale_threshold_renews_lease_and_second_worker_cannot_apply_concurrently(): void
    {
        Storage::fake('local');
        Queue::fake();

        Carbon::setTestNow('2026-10-29 12:00:00');

        $branch = $this->createBranch(['status' => BranchStatus::ACTIVE]);
        $doc1Uuid = (string) Str::uuid();
        $doc2Uuid = (string) Str::uuid();
        $doc3Uuid = (string) Str::uuid();

        [$batch] = $this->createStoredBatch($branch, [
            [
                'type' => 'category',
                'uuid' => $doc1Uuid,
                'version' => 1,
                'op' => 'upsert',
                'occurred_at' => now()->toIso8601String(),
                'data' => ['name' => 'Cat 1'],
            ],
            [
                'type' => 'category',
                'uuid' => $doc2Uuid,
                'version' => 2,
                'op' => 'upsert',
                'occurred_at' => now()->toIso8601String(),
                'data' => ['name' => 'Cat 2'],
            ],
            [
                'type' => 'category',
                'uuid' => $doc3Uuid,
                'version' => 3,
                'op' => 'upsert',
                'occurred_at' => now()->toIso8601String(),
                'data' => ['name' => 'Cat 3'],
            ],
        ]);

        $worker2AttemptedCalls = 0;
        $recoveryCommandPushedCount = 0;

        $registry = new CountingRecoveryTestRegistry(
            null,
            function (string $uuid, Branch $b, array $doc) use ($batch, $branch, &$worker2AttemptedCalls, &$recoveryCommandPushedCount, $doc1Uuid, $doc2Uuid, $doc3Uuid) {
                if ($uuid === $doc1Uuid) {
                    Carbon::setTestNow(now()->addSeconds(20)); // 12:00:20
                } elseif ($uuid === $doc2Uuid) {
                    Carbon::setTestNow(now()->addSeconds(20)); // 12:00:40 (40s > 30s initial lease)

                    // Inside doc 2, Worker 1 renewed lease at doc 2 to 12:00:40 + 30s = 12:01:10
                    // While Worker 1 is still running, Worker 2 attempts to process concurrently
                    $worker2Registry = new CountingRecoveryTestRegistry;
                    $worker2Job = new ProcessIngestBatch($batch->id, [$doc1Uuid, $doc2Uuid, $doc3Uuid], $branch->id, [0, 1, 2]);
                    $worker2Job->handle($worker2Registry);

                    $worker2AttemptedCalls += count($worker2Registry->calls);

                    // Also verify scheduled recovery command does not recover active worker!
                    $preCount = Queue::pushed(ProcessIngestBatch::class)->count();
                    Artisan::call('ingest:recover');
                    $postCount = Queue::pushed(ProcessIngestBatch::class)->count();
                    $recoveryCommandPushedCount += ($postCount - $preCount);
                } elseif ($uuid === $doc3Uuid) {
                    Carbon::setTestNow(now()->addSeconds(10)); // 12:00:50
                }
            }
        );

        $worker1Job = new ProcessIngestBatch($batch->id, [$doc1Uuid, $doc2Uuid, $doc3Uuid], $branch->id, [0, 1, 2]);
        $worker1Job->handle($registry);

        // Worker 2 must have made 0 calls because Worker 1 held the renewable lease!
        $this->assertSame(0, $worker2AttemptedCalls);

        // Scheduled recovery must not have pushed because lease was active!
        $this->assertSame(0, $recoveryCommandPushedCount);

        // Worker 1 applied all 3 docs in order
        $this->assertCount(3, $registry->calls);
        $this->assertSame($doc1Uuid, $registry->calls[0]['uuid']);
        $this->assertSame($doc2Uuid, $registry->calls[1]['uuid']);
        $this->assertSame($doc3Uuid, $registry->calls[2]['uuid']);

        // Batch completed
        $batch->refresh();
        $this->assertSame('processed', $batch->status);
        $this->assertNull($batch->lease_expires_at);

        Queue::assertPushed(RecomputeBranchDay::class, 1);
    }

    public function test_stale_worker_fencing_after_lease_takeover_prevents_stale_worker_from_writing_or_completing(): void
    {
        Storage::fake('local');
        Queue::fake();

        Carbon::setTestNow('2026-10-29 12:00:00');

        $branch = $this->createBranch(['status' => BranchStatus::ACTIVE]);
        $doc1Uuid = (string) Str::uuid();
        $doc2Uuid = (string) Str::uuid();

        [$batch] = $this->createStoredBatch($branch, [
            [
                'type' => 'category',
                'uuid' => $doc1Uuid,
                'version' => 1,
                'op' => 'upsert',
                'occurred_at' => now()->toIso8601String(),
                'data' => ['name' => 'Cat 1'],
            ],
            [
                'type' => 'category',
                'uuid' => $doc2Uuid,
                'version' => 2,
                'op' => 'upsert',
                'occurred_at' => now()->toIso8601String(),
                'data' => ['name' => 'Cat 2'],
            ],
        ]);

        $worker2Completed = false;

        $registry = new CountingRecoveryTestRegistry(
            null,
            function (string $uuid, Branch $b, array $doc) use ($batch, $branch, &$worker2Completed, $doc1Uuid, $doc2Uuid) {
                if ($uuid === $doc1Uuid) {
                    // Worker 1 processes Doc 1.
                    // Simulate Worker 1 stalling: time advances 100 seconds without renewing lease!
                    Carbon::setTestNow(now()->addSeconds(100)); // 12:01:40 (lease expired at 12:00:30)

                    // Worker 2 detects expired lease and takes over!
                    $worker2Registry = new CountingRecoveryTestRegistry;
                    $worker2Job = new ProcessIngestBatch($batch->id, [$doc1Uuid, $doc2Uuid], $branch->id, [0, 1]);
                    $worker2Job->handle($worker2Registry);

                    $worker2Completed = true;

                    // Worker 2 handled Doc 1 (skipped or applied) and Doc 2, and marked batch processed!
                    $this->assertSame('processed', $batch->fresh()->status);
                }
            }
        );

        $worker1Job = new ProcessIngestBatch($batch->id, [$doc1Uuid, $doc2Uuid], $branch->id, [0, 1]);
        // Worker 1 resumes after Doc 1. When trying to process Doc 2, Worker 1 must be fenced!
        $worker1Job->handle($registry);

        $this->assertTrue($worker2Completed);

        // Worker 1 was fenced BEFORE writing Doc 2!
        // So Worker 1 made exactly 1 call (Doc 1) on $registry!
        $this->assertCount(1, $registry->calls);
        $this->assertSame($doc1Uuid, $registry->calls[0]['uuid']);

        // Batch remains processed with Worker 2's completion
        $batch->refresh();
        $this->assertSame('processed', $batch->status);
    }

    public function test_stale_worker_cannot_record_rejection_after_fencing(): void
    {
        Storage::fake('local');
        Queue::fake();

        Carbon::setTestNow('2026-10-29 12:00:00');

        $branch = $this->createBranch(['status' => BranchStatus::ACTIVE]);
        $doc1Uuid = (string) Str::uuid();
        $doc2Uuid = (string) Str::uuid();

        [$batch] = $this->createStoredBatch($branch, [
            [
                'type' => 'category',
                'uuid' => $doc1Uuid,
                'version' => 1,
                'op' => 'upsert',
                'occurred_at' => now()->toIso8601String(),
                'data' => ['name' => 'Doc 1'],
            ],
            [
                'type' => 'category',
                'uuid' => $doc2Uuid,
                'version' => 2,
                'op' => 'upsert',
                'occurred_at' => now()->toIso8601String(),
                'data' => ['name' => 'Failing Doc 2'],
            ],
        ]);

        // Doc 2 will fail in registry
        $registry = new CountingRecoveryTestRegistry(
            $doc2Uuid,
            function (string $uuid, Branch $b, array $doc) use ($batch, $doc1Uuid) {
                if ($uuid === $doc1Uuid) {
                    // Stalling: advance 100 seconds past lease expiration
                    Carbon::setTestNow(now()->addSeconds(100));

                    // Worker 2 claims batch
                    $batch->update([
                        'claim_token' => (string) Str::uuid(),
                        'fencing_token' => 2,
                        'lease_expires_at' => now()->addSeconds(30),
                        'status' => 'processing',
                    ]);
                }
            }
        );

        $worker1Job = new ProcessIngestBatch($batch->id, [$doc1Uuid, $doc2Uuid], $branch->id, [0, 1]);
        $worker1Job->handle($registry);

        // Doc 2 must NOT have an ingest_rejections record because Worker 1 was fenced before writing rejection!
        $this->assertDatabaseMissing('ingest_rejections', [
            'ingest_batch_id' => $batch->id,
            'doc_uuid' => $doc2Uuid,
        ]);
    }

    public function test_worker_termination_and_recovery_by_scheduled_command(): void
    {
        Storage::fake('local');
        Queue::fake();

        Carbon::setTestNow('2026-10-29 12:00:00');

        $branch = $this->createBranch(['status' => BranchStatus::ACTIVE]);
        $doc1Uuid = (string) Str::uuid();

        [$batch] = $this->createStoredBatch($branch, [
            [
                'type' => 'category',
                'uuid' => $doc1Uuid,
                'version' => 1,
                'op' => 'upsert',
                'occurred_at' => now()->toIso8601String(),
                'data' => ['name' => 'Recoverable'],
            ],
        ], [
            // Worker 1 claimed 60s ago with a 30s lease (expired 30s ago)
            'status' => 'processing',
            'claim_token' => (string) Str::uuid(),
            'fencing_token' => 1,
            'lease_expires_at' => now()->subSeconds(30),
            'updated_at' => now()->subSeconds(60),
        ]);

        $exitCode = Artisan::call('ingest:recover');
        $this->assertSame(0, $exitCode);

        // The batch was redispatched!
        Queue::assertPushed(ProcessIngestBatch::class, function (ProcessIngestBatch $job) use ($batch) {
            return $job->batchId === $batch->id;
        });

        // Now simulate Worker 2 processing the recovery job
        $registry = new CountingRecoveryTestRegistry;
        $recoveryJob = new ProcessIngestBatch($batch->id, [$doc1Uuid], $branch->id, [0]);
        $recoveryJob->handle($registry);

        // Batch finishes processed with incremented fencing token (fencing_token = 2)
        $batch->refresh();
        $this->assertSame('processed', $batch->status);
        $this->assertSame(2, (int) $batch->fencing_token);
        $this->assertCount(1, $registry->calls);
    }

    public function test_non_database_queue_retry_after_configurations(): void
    {
        config(['queue.default' => 'redis']);
        config(['queue.connections.redis.retry_after' => 180]);
        $this->assertSame(180, ProcessIngestBatch::getRetryAfterForConnection('redis'));
        $this->assertSame(180, ProcessIngestBatch::getRetryAfterForConnection());

        config(['queue.default' => 'sqs']);
        config(['queue.connections.sqs.visibility_timeout' => 120]);
        $this->assertSame(120, ProcessIngestBatch::getRetryAfterForConnection('sqs'));
        $this->assertSame(120, ProcessIngestBatch::getRetryAfterForConnection());

        config(['queue.default' => 'beanstalkd']);
        config(['queue.connections.beanstalkd.retry_after' => 240]);
        $this->assertSame(240, ProcessIngestBatch::getRetryAfterForConnection('beanstalkd'));
        $this->assertSame(240, ProcessIngestBatch::getRetryAfterForConnection());

        // Reset to default
        config(['queue.default' => 'sync']);
    }

    public function test_duplicate_receipt_on_active_batch_preserves_api_contract_and_does_not_interrupt_live_worker(): void
    {
        Storage::fake('local');
        Queue::fake();

        $branch = $this->createBranch(['status' => BranchStatus::ACTIVE]);
        $token = $branch->issueIngestToken();

        $batchUuid = (string) Str::uuid();
        $docUuid = (string) Str::uuid();

        [$batch] = $this->createStoredBatch($branch, [
            [
                'type' => 'category',
                'uuid' => $docUuid,
                'version' => 1,
                'op' => 'upsert',
                'occurred_at' => now()->toIso8601String(),
                'data' => ['name' => 'Active Item'],
            ],
        ], [
            'batch_uuid' => $batchUuid,
            'status' => 'processing',
            'claim_token' => (string) Str::uuid(),
            'fencing_token' => 1,
            'lease_expires_at' => now()->addSeconds(25), // Fresh active lease!
            'updated_at' => now(),
        ]);

        $envelope = [
            'schema_version' => 1,
            'batch_uuid' => $batchUuid,
            'branch_uuid' => $branch->uuid,
            'pos_version' => '2.4.0',
            'sent_at' => now()->toIso8601String(),
            'seq_from' => 1,
            'seq_to' => 1,
            'documents' => [
                [
                    'type' => 'category',
                    'uuid' => $docUuid,
                    'version' => 1,
                    'op' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'data' => ['name' => 'Active Item'],
                ],
            ],
        ];

        // Duplicate receipt returns 409 matching public API contract
        $response = $this->withToken($token)->postJson(route('api.v1.ingest.batches'), $envelope);
        $response->assertStatus(409)
            ->assertJson([
                'batch_uuid' => $batchUuid,
                'accepted' => [$docUuid],
                'rejected' => [],
            ]);

        // Job pushed as safety net
        Queue::assertPushed(ProcessIngestBatch::class);

        // When the pushed job executes, it sees active lease and does NOT claim or interfere
        $registry = new CountingRecoveryTestRegistry;
        $job = new ProcessIngestBatch($batch->id, [$docUuid], $branch->id, [0]);
        $job->handle($registry);

        $this->assertCount(0, $registry->calls);
        $this->assertSame(1, (int) $batch->fresh()->fencing_token);
        $this->assertSame('processing', $batch->fresh()->status);
    }
}
