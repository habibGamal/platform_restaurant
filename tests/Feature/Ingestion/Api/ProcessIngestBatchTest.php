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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class FakeIngestBatchTestRegistry implements DocumentHandlerRegistry
{
    /** @var list<array{branch_id: int, uuid: string, type: string}> */
    public array $calls = [];

    public function for(string $type): DocumentHandler
    {
        return new class($this, $type) implements DocumentHandler
        {
            public function __construct(private FakeIngestBatchTestRegistry $registry, private string $type) {}

            public function type(): string
            {
                return $this->type;
            }

            public function handle(Branch $branch, array $document): HandleResult
            {
                $this->registry->calls[] = [
                    'branch_id' => $branch->id,
                    'uuid' => (string) $document['uuid'],
                    'type' => $this->type,
                ];

                if ($this->type === 'product') {
                    return HandleResult::applied(['2026-10-30']);
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

class FakeFailingBatchTestRegistry implements DocumentHandlerRegistry
{
    /** @var list<string> */
    public array $handled = [];

    public function __construct(private string $failingUuid) {}

    public function for(string $type): DocumentHandler
    {
        return new class($this, $this->failingUuid) implements DocumentHandler
        {
            public function __construct(private FakeFailingBatchTestRegistry $parent, private string $failingUuid) {}

            public function type(): string
            {
                return 'category';
            }

            public function handle(Branch $branch, array $document): HandleResult
            {
                if ($document['uuid'] === $this->failingUuid) {
                    throw new RuntimeException('Handler failed for doc2');
                }

                $this->parent->handled[] = (string) $document['uuid'];

                return HandleResult::applied(['2026-10-29']);
            }
        };
    }

    public function types(): array
    {
        return ['category'];
    }
}

class ProcessIngestBatchTest extends TestCase
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

    public function test_handlers_called_in_order_and_recompute_dispatched_once_per_date_with_delay(): void
    {
        Storage::fake('local');
        Queue::fake();

        $branch = $this->createBranch([
            'status' => BranchStatus::ACTIVE,
            'last_seq' => 50,
        ]);

        $batchUuid = (string) Str::uuid();
        $doc1Uuid = (string) Str::uuid();
        $doc2Uuid = (string) Str::uuid();
        $doc3Uuid = (string) Str::uuid();

        $envelope = [
            'schema_version' => 1,
            'batch_uuid' => $batchUuid,
            'branch_uuid' => $branch->uuid,
            'pos_version' => '2.4.0',
            'sent_at' => now()->toIso8601String(),
            'seq_from' => 51,
            'seq_to' => 60,
            'documents' => [
                [
                    'type' => 'category',
                    'uuid' => $doc1Uuid,
                    'version' => 51,
                    'op' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'data' => ['name' => 'Cat 1'],
                ],
                [
                    'type' => 'category',
                    'uuid' => $doc2Uuid,
                    'version' => 52,
                    'op' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'data' => ['name' => 'Cat 2'],
                ],
                [
                    'type' => 'product',
                    'uuid' => $doc3Uuid,
                    'version' => 53,
                    'op' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'data' => [
                        'name' => 'Burger',
                        'type' => 'manufactured',
                        'price' => '100.00',
                        'cost' => '60.00',
                    ],
                ],
            ],
        ];

        $storagePath = "ingest/{$branch->organization_id}/{$branch->id}/2026/10/{$batchUuid}.json";
        $json = json_encode($envelope);
        $this->assertIsString($json);
        Storage::disk('local')->put($storagePath, $json);

        $batch = IngestBatch::create([
            'organization_id' => $branch->organization_id,
            'branch_id' => $branch->id,
            'batch_uuid' => $batchUuid,
            'schema_version' => 1,
            'pos_version' => '2.4.0',
            'seq_from' => 51,
            'seq_to' => 60,
            'docs_count' => 3,
            'accepted_count' => 3,
            'rejected_count' => 0,
            'status' => 'received',
            'result' => [
                'batch_uuid' => $batchUuid,
                'accepted' => [$doc1Uuid, $doc2Uuid, $doc3Uuid],
                'rejected' => [],
            ],
            'storage_path' => $storagePath,
            'received_at' => now(),
        ]);

        $fakeRegistry = new FakeIngestBatchTestRegistry;
        $this->app->instance(DocumentHandlerRegistry::class, $fakeRegistry);

        $job = new ProcessIngestBatch($batch->id, [$doc1Uuid, $doc2Uuid, $doc3Uuid], $branch->id);
        $job->handle($fakeRegistry);

        // 1. Handlers called in order
        $this->assertCount(3, $fakeRegistry->calls);
        $this->assertSame($doc1Uuid, $fakeRegistry->calls[0]['uuid']);
        $this->assertSame($doc2Uuid, $fakeRegistry->calls[1]['uuid']);
        $this->assertSame($doc3Uuid, $fakeRegistry->calls[2]['uuid']);

        // 2. RecomputeBranchDay dispatched once per unique date with delay
        Queue::assertPushed(RecomputeBranchDay::class, 2);

        Queue::assertPushed(RecomputeBranchDay::class, function (RecomputeBranchDay $job) use ($branch) {
            return $job->branchId === $branch->id
                && $job->businessDate === '2026-10-29'
                && $job->delay !== null;
        });

        Queue::assertPushed(RecomputeBranchDay::class, function (RecomputeBranchDay $job) use ($branch) {
            return $job->branchId === $branch->id
                && $job->businessDate === '2026-10-30'
                && $job->delay !== null;
        });

        // 3. Batch updated to processed, branch last_seq updated to seq_to (60)
        $batch->refresh();
        $this->assertSame('processed', $batch->status);
        $this->assertNotNull($batch->processed_at);

        $branch->refresh();
        $this->assertSame(60, $branch->last_seq);
    }

    public function test_handler_exception_records_rejection_row_and_continues(): void
    {
        Storage::fake('local');
        Queue::fake();

        $branch = $this->createBranch(['status' => BranchStatus::ACTIVE]);

        $batchUuid = (string) Str::uuid();
        $doc1Uuid = (string) Str::uuid();
        $doc2Uuid = (string) Str::uuid();
        $doc3Uuid = (string) Str::uuid();

        $envelope = [
            'schema_version' => 1,
            'batch_uuid' => $batchUuid,
            'branch_uuid' => $branch->uuid,
            'pos_version' => '2.4.0',
            'sent_at' => now()->toIso8601String(),
            'seq_from' => 1,
            'seq_to' => 3,
            'documents' => [
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
                [
                    'type' => 'category',
                    'uuid' => $doc3Uuid,
                    'version' => 3,
                    'op' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'data' => ['name' => 'Third'],
                ],
            ],
        ];

        $storagePath = "ingest/{$branch->organization_id}/{$branch->id}/2026/10/{$batchUuid}.json";
        $json = json_encode($envelope);
        $this->assertIsString($json);
        Storage::disk('local')->put($storagePath, $json);

        $batch = IngestBatch::create([
            'organization_id' => $branch->organization_id,
            'branch_id' => $branch->id,
            'batch_uuid' => $batchUuid,
            'schema_version' => 1,
            'pos_version' => '2.4.0',
            'seq_from' => 1,
            'seq_to' => 3,
            'docs_count' => 3,
            'accepted_count' => 3,
            'rejected_count' => 0,
            'status' => 'received',
            'result' => [
                'batch_uuid' => $batchUuid,
                'accepted' => [$doc1Uuid, $doc2Uuid, $doc3Uuid],
                'rejected' => [],
            ],
            'storage_path' => $storagePath,
            'received_at' => now(),
        ]);

        $fakeRegistry = new FakeFailingBatchTestRegistry($doc2Uuid);
        $this->app->instance(DocumentHandlerRegistry::class, $fakeRegistry);

        $job = new ProcessIngestBatch($batch->id, [$doc1Uuid, $doc2Uuid, $doc3Uuid], $branch->id);
        $job->handle($fakeRegistry);

        // Doc 1 and Doc 3 were handled
        $this->assertSame([$doc1Uuid, $doc3Uuid], $fakeRegistry->handled);

        // Doc 2 caused an ingest_rejection row
        $this->assertDatabaseHas('ingest_rejections', [
            'ingest_batch_id' => $batch->id,
            'branch_id' => $branch->id,
            'doc_uuid' => $doc2Uuid,
            'reason' => 'Handler failed for doc2',
        ]);

        // Batch finishes processed
        $batch->refresh();
        $this->assertSame('processed', $batch->status);
    }

    public function test_unreadable_file_marks_batch_failed(): void
    {
        Storage::fake('local');

        $branch = $this->createBranch(['status' => BranchStatus::ACTIVE]);

        $batch = IngestBatch::create([
            'organization_id' => $branch->organization_id,
            'branch_id' => $branch->id,
            'batch_uuid' => (string) Str::uuid(),
            'schema_version' => 1,
            'pos_version' => '2.4.0',
            'seq_from' => 1,
            'seq_to' => 1,
            'docs_count' => 1,
            'accepted_count' => 1,
            'rejected_count' => 0,
            'status' => 'received',
            'storage_path' => 'nonexistent/path.json',
            'received_at' => now(),
        ]);

        $fakeRegistry = $this->createMock(DocumentHandlerRegistry::class);

        $job = new ProcessIngestBatch($batch->id, [], $branch->id);
        $job->handle($fakeRegistry);

        $batch->refresh();
        $this->assertSame('failed', $batch->status);
        $this->assertNotNull($batch->error);
        $this->assertNotNull($batch->processed_at);
    }

    public function test_accepted_product_and_rejected_recipe_same_uuid_only_product_handled(): void
    {
        Storage::fake('local');
        Queue::fake();

        $branch = $this->createBranch(['status' => BranchStatus::ACTIVE]);

        $batchUuid = (string) Str::uuid();
        $sharedUuid = (string) Str::uuid();

        // Product is valid (index 0, accepted), Recipe has same UUID but was rejected during validation (index 1)
        $envelope = [
            'schema_version' => 1,
            'batch_uuid' => $batchUuid,
            'branch_uuid' => $branch->uuid,
            'pos_version' => '2.4.0',
            'sent_at' => now()->toIso8601String(),
            'seq_from' => 1,
            'seq_to' => 2,
            'documents' => [
                [
                    'type' => 'product',
                    'uuid' => $sharedUuid,
                    'version' => 1,
                    'op' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'data' => [
                        'name' => 'Burger',
                        'type' => 'food',
                        'price' => '50.00',
                        'cost' => '30.00',
                    ],
                ],
                [
                    'type' => 'recipe',
                    'uuid' => $sharedUuid,
                    'version' => 1,
                    'op' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'data' => [
                        'product_uuid' => $sharedUuid,
                        'components' => 'invalid-components-not-an-array',
                    ],
                ],
            ],
        ];

        $storagePath = "ingest/{$branch->organization_id}/{$branch->id}/2026/10/{$batchUuid}.json";
        $json = json_encode($envelope);
        $this->assertIsString($json);
        Storage::disk('local')->put($storagePath, $json);

        $batch = IngestBatch::create([
            'organization_id' => $branch->organization_id,
            'branch_id' => $branch->id,
            'batch_uuid' => $batchUuid,
            'schema_version' => 1,
            'pos_version' => '2.4.0',
            'seq_from' => 1,
            'seq_to' => 2,
            'docs_count' => 2,
            'accepted_count' => 1,
            'rejected_count' => 1,
            'status' => 'received',
            'result' => [
                'batch_uuid' => $batchUuid,
                'accepted' => [$sharedUuid],
                'rejected' => [
                    [
                        'uuid' => $sharedUuid,
                        'type' => 'recipe',
                        'reason' => 'Invalid components',
                    ],
                ],
            ],
            'storage_path' => $storagePath,
            'received_at' => now(),
        ]);

        $fakeRegistry = new FakeIngestBatchTestRegistry;
        $this->app->instance(DocumentHandlerRegistry::class, $fakeRegistry);

        // Job is constructed with accepted UUIDs [$sharedUuid] and acceptedIndices [0]
        $job = new ProcessIngestBatch($batch->id, [$sharedUuid], $branch->id, [0]);
        $job->handle($fakeRegistry);

        // Crucial regression check: ONLY product handler was called! Recipe was rejected and MUST NOT run!
        $this->assertCount(1, $fakeRegistry->calls);
        $this->assertSame('product', $fakeRegistry->calls[0]['type']);
        $this->assertSame($sharedUuid, $fakeRegistry->calls[0]['uuid']);
    }

    public function test_accepted_product_and_rejected_recipe_same_uuid_without_explicit_indices_only_product_handled(): void
    {
        Storage::fake('local');
        Queue::fake();

        $branch = $this->createBranch(['status' => BranchStatus::ACTIVE]);

        $batchUuid = (string) Str::uuid();
        $sharedUuid = (string) Str::uuid();

        // Product is valid (index 0, accepted), Recipe has same UUID but was rejected during validation (index 1)
        $envelope = [
            'schema_version' => 1,
            'batch_uuid' => $batchUuid,
            'branch_uuid' => $branch->uuid,
            'pos_version' => '2.4.0',
            'sent_at' => now()->toIso8601String(),
            'seq_from' => 1,
            'seq_to' => 2,
            'documents' => [
                [
                    'type' => 'product',
                    'uuid' => $sharedUuid,
                    'version' => 1,
                    'op' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'data' => [
                        'name' => 'Burger',
                        'type' => 'food',
                        'price' => '50.00',
                        'cost' => '30.00',
                    ],
                ],
                [
                    'type' => 'recipe',
                    'uuid' => $sharedUuid,
                    'version' => 1,
                    'op' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'data' => [
                        'product_uuid' => $sharedUuid,
                        'components' => 'invalid-components-not-an-array',
                    ],
                ],
            ],
        ];

        $storagePath = "ingest/{$branch->organization_id}/{$branch->id}/2026/10/{$batchUuid}.json";
        $json = json_encode($envelope);
        $this->assertIsString($json);
        Storage::disk('local')->put($storagePath, $json);

        $batch = IngestBatch::create([
            'organization_id' => $branch->organization_id,
            'branch_id' => $branch->id,
            'batch_uuid' => $batchUuid,
            'schema_version' => 1,
            'pos_version' => '2.4.0',
            'seq_from' => 1,
            'seq_to' => 2,
            'docs_count' => 2,
            'accepted_count' => 1,
            'rejected_count' => 1,
            'status' => 'received',
            'result' => [
                'batch_uuid' => $batchUuid,
                'accepted' => [$sharedUuid],
                'rejected' => [
                    [
                        'uuid' => $sharedUuid,
                        'type' => 'recipe',
                        'reason' => 'Invalid components',
                    ],
                ],
            ],
            'storage_path' => $storagePath,
            'received_at' => now(),
        ]);

        $fakeRegistry = new FakeIngestBatchTestRegistry;
        $this->app->instance(DocumentHandlerRegistry::class, $fakeRegistry);

        // Job is constructed WITHOUT acceptedIndices! It must still only handle the accepted product!
        $job = new ProcessIngestBatch($batch->id, [$sharedUuid], $branch->id);
        $job->handle($fakeRegistry);

        $this->assertCount(1, $fakeRegistry->calls);
        $this->assertSame('product', $fakeRegistry->calls[0]['type']);
        $this->assertSame($sharedUuid, $fakeRegistry->calls[0]['uuid']);
    }

    public function test_valid_product_and_recipe_same_uuid_both_handled(): void
    {
        Storage::fake('local');
        Queue::fake();

        $branch = $this->createBranch(['status' => BranchStatus::ACTIVE]);

        $batchUuid = (string) Str::uuid();
        $sharedUuid = (string) Str::uuid();

        $envelope = [
            'schema_version' => 1,
            'batch_uuid' => $batchUuid,
            'branch_uuid' => $branch->uuid,
            'pos_version' => '2.4.0',
            'sent_at' => now()->toIso8601String(),
            'seq_from' => 1,
            'seq_to' => 2,
            'documents' => [
                [
                    'type' => 'product',
                    'uuid' => $sharedUuid,
                    'version' => 1,
                    'op' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'data' => [
                        'name' => 'Burger',
                        'type' => 'food',
                        'price' => '50.00',
                        'cost' => '30.00',
                    ],
                ],
                [
                    'type' => 'recipe',
                    'uuid' => $sharedUuid,
                    'version' => 1,
                    'op' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'data' => [
                        'product_uuid' => $sharedUuid,
                        'components' => [],
                    ],
                ],
            ],
        ];

        $storagePath = "ingest/{$branch->organization_id}/{$branch->id}/2026/10/{$batchUuid}.json";
        $json = json_encode($envelope);
        $this->assertIsString($json);
        Storage::disk('local')->put($storagePath, $json);

        $batch = IngestBatch::create([
            'organization_id' => $branch->organization_id,
            'branch_id' => $branch->id,
            'batch_uuid' => $batchUuid,
            'schema_version' => 1,
            'pos_version' => '2.4.0',
            'seq_from' => 1,
            'seq_to' => 2,
            'docs_count' => 2,
            'accepted_count' => 2,
            'rejected_count' => 0,
            'status' => 'received',
            'result' => [
                'batch_uuid' => $batchUuid,
                'accepted' => [$sharedUuid, $sharedUuid],
                'rejected' => [],
            ],
            'storage_path' => $storagePath,
            'received_at' => now(),
        ]);

        $fakeRegistry = new FakeIngestBatchTestRegistry;
        $this->app->instance(DocumentHandlerRegistry::class, $fakeRegistry);

        $job = new ProcessIngestBatch($batch->id, [$sharedUuid, $sharedUuid], $branch->id, [0, 1]);
        $job->handle($fakeRegistry);

        // Both product and recipe were handled in order
        $this->assertCount(2, $fakeRegistry->calls);
        $this->assertSame('product', $fakeRegistry->calls[0]['type']);
        $this->assertSame($sharedUuid, $fakeRegistry->calls[0]['uuid']);
        $this->assertSame('recipe', $fakeRegistry->calls[1]['type']);
        $this->assertSame($sharedUuid, $fakeRegistry->calls[1]['uuid']);
    }
}
