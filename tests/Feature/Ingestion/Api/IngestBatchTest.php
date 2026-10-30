<?php

namespace Tests\Feature\Ingestion\Api;

use App\Domain\Ingestion\Validation\DocumentRules;
use App\Enums\BranchStatus;
use App\Jobs\ProcessIngestBatch;
use App\Models\Branch;
use App\Models\IngestBatch;
use App\Models\Organization;
use App\Models\Restaurant;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IngestBatchTest extends TestCase
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

    public function test_plain_body_accepted_and_dispatches_job(): void
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
            'seq_from' => 100,
            'seq_to' => 101,
            'documents' => [
                [
                    'type' => 'category',
                    'uuid' => $docUuid,
                    'version' => 101,
                    'op' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'data' => [
                        'name' => 'Main Dishes',
                    ],
                ],
            ],
        ];

        $response = $this->withToken($token)->postJson(route('api.v1.ingest.batches'), $envelope);

        $response->assertStatus(202)
            ->assertJson([
                'batch_uuid' => $batchUuid,
                'accepted' => [$docUuid],
                'rejected' => [],
            ]);

        $this->assertDatabaseHas('ingest_batches', [
            'batch_uuid' => $batchUuid,
            'branch_id' => $branch->id,
            'status' => 'received',
            'docs_count' => 1,
            'accepted_count' => 1,
            'rejected_count' => 0,
        ]);

        $batch = IngestBatch::where('batch_uuid', $batchUuid)->firstOrFail();
        Storage::disk('local')->assertExists($batch->storage_path);

        Queue::assertPushed(ProcessIngestBatch::class, function (ProcessIngestBatch $job) use ($batch, $docUuid) {
            return $job->batchId === $batch->id
                && $job->acceptedUuids === [$docUuid]
                && $job->queue === 'ingest';
        });

        $branch->refresh();
        $this->assertNotNull($branch->last_seen_at);
        $this->assertNotNull($branch->last_batch_at);
    }

    public function test_gzip_body_handled_properly(): void
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
            'seq_from' => 10,
            'seq_to' => 11,
            'documents' => [
                [
                    'type' => 'supplier',
                    'uuid' => $docUuid,
                    'version' => 11,
                    'op' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'data' => [
                        'name' => 'Al-Amal Suppliers',
                        'phone' => '01000000000',
                    ],
                ],
            ],
        ];

        $json = json_encode($envelope);
        $this->assertIsString($json);
        $compressed = gzencode($json);
        $this->assertIsString($compressed);

        $server = $this->transformHeadersToServerVars([
            'Authorization' => 'Bearer '.$token,
            'Content-Encoding' => 'gzip',
            'Content-Type' => 'application/json',
        ]);

        $response = $this->call('POST', route('api.v1.ingest.batches'), [], [], [], $server, $compressed);

        $response->assertStatus(202)
            ->assertJson([
                'batch_uuid' => $batchUuid,
                'accepted' => [$docUuid],
                'rejected' => [],
            ]);

        $batch = IngestBatch::where('batch_uuid', $batchUuid)->firstOrFail();
        $this->assertStringEndsWith('.json.gz', $batch->storage_path);
        Storage::disk('local')->assertExists($batch->storage_path);
    }

    public function test_invalid_gzip_returns_422(): void
    {
        $branch = $this->createBranch(['status' => BranchStatus::ACTIVE]);
        $token = $branch->issueIngestToken();

        $server = $this->transformHeadersToServerVars([
            'Authorization' => 'Bearer '.$token,
            'Content-Encoding' => 'gzip',
            'Content-Type' => 'application/json',
        ]);

        $response = $this->call('POST', route('api.v1.ingest.batches'), [], [], [], $server, 'not-a-valid-gzip-string');

        $response->assertStatus(422);
    }

    public function test_duplicate_batch_uuid_returns_409_with_stored_body(): void
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
            'seq_to' => 2,
            'documents' => [
                [
                    'type' => 'category',
                    'uuid' => $docUuid,
                    'version' => 1,
                    'op' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'data' => ['name' => 'Drinks'],
                ],
            ],
        ];

        $first = $this->withToken($token)->postJson(route('api.v1.ingest.batches'), $envelope);
        $first->assertStatus(202);

        $second = $this->withToken($token)->postJson(route('api.v1.ingest.batches'), $envelope);
        $second->assertStatus(409)
            ->assertJson($first->json());
    }

    public function test_wrong_branch_uuid_returns_403(): void
    {
        $branch = $this->createBranch(['status' => BranchStatus::ACTIVE]);
        $otherBranch = $this->createBranch(['status' => BranchStatus::ACTIVE]);
        $token = $branch->issueIngestToken();

        $envelope = [
            'schema_version' => 1,
            'batch_uuid' => (string) Str::uuid(),
            'branch_uuid' => $otherBranch->uuid, // Mismatched!
            'pos_version' => '2.4.0',
            'sent_at' => now()->toIso8601String(),
            'seq_from' => 1,
            'seq_to' => 1,
            'documents' => [],
        ];

        $this->withToken($token)->postJson(route('api.v1.ingest.batches'), $envelope)
            ->assertStatus(403);
    }

    public function test_bad_document_rejected_but_valid_accepted(): void
    {
        Storage::fake('local');
        Queue::fake();

        $branch = $this->createBranch(['status' => BranchStatus::ACTIVE]);
        $token = $branch->issueIngestToken();

        $batchUuid = (string) Str::uuid();
        $validDocUuid = (string) Str::uuid();
        $badDocUuid = (string) Str::uuid();

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
                    'type' => 'category',
                    'uuid' => $validDocUuid,
                    'version' => 1,
                    'op' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'data' => ['name' => 'Appetizers'],
                ],
                [
                    'type' => 'order',
                    'uuid' => $badDocUuid,
                    'version' => 2,
                    'op' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'data' => [
                        // Missing required order fields (number, shift_uuid, total, etc.)
                        'number' => 'ORD-1',
                    ],
                ],
            ],
        ];

        $response = $this->withToken($token)->postJson(route('api.v1.ingest.batches'), $envelope);

        $response->assertStatus(202)
            ->assertJson([
                'batch_uuid' => $batchUuid,
                'accepted' => [$validDocUuid],
            ]);

        $this->assertCount(1, $response->json('rejected'));
        $this->assertSame($badDocUuid, $response->json('rejected.0.uuid'));
        $this->assertSame('order', $response->json('rejected.0.type'));
        $this->assertNotEmpty($response->json('rejected.0.reason'));

        // Ensure rejection row created in DB
        $this->assertDatabaseHas('ingest_rejections', [
            'branch_id' => $branch->id,
            'doc_uuid' => $badDocUuid,
            'doc_type' => 'order',
        ]);
    }

    public function test_limits_return_413(): void
    {
        $branch = $this->createBranch(['status' => BranchStatus::ACTIVE]);
        $token = $branch->issueIngestToken();

        // 1. More than 500 documents
        $documents = [];
        for ($i = 0; $i < 501; $i++) {
            $documents[] = [
                'type' => 'category',
                'uuid' => (string) Str::uuid(),
                'version' => $i + 1,
                'op' => 'upsert',
                'occurred_at' => now()->toIso8601String(),
                'data' => ['name' => "Cat {$i}"],
            ];
        }

        $envelope = [
            'schema_version' => 1,
            'batch_uuid' => (string) Str::uuid(),
            'branch_uuid' => $branch->uuid,
            'pos_version' => '2.4.0',
            'sent_at' => now()->toIso8601String(),
            'seq_from' => 1,
            'seq_to' => 501,
            'documents' => $documents,
        ];

        $this->withToken($token)->postJson(route('api.v1.ingest.batches'), $envelope)
            ->assertStatus(413);

        // 2. Payload size > 5 MB
        $hugeData = str_repeat('A', (5 * 1024 * 1024) + 10);
        $server = $this->transformHeadersToServerVars([
            'Authorization' => 'Bearer '.$token,
            'Content-Type' => 'application/json',
        ]);

        $this->call('POST', route('api.v1.ingest.batches'), [], [], [], $server, $hugeData)
            ->assertStatus(413);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->postJson(route('api.v1.ingest.batches'), [])
            ->assertStatus(401);
    }

    public function test_suspended_branch_returns_403(): void
    {
        $branch = $this->createBranch([
            'status' => BranchStatus::SUSPENDED,
        ]);
        $token = $branch->issueIngestToken();

        $this->withToken($token)->postJson(route('api.v1.ingest.batches'), [
            'schema_version' => 1,
            'batch_uuid' => (string) Str::uuid(),
            'branch_uuid' => $branch->uuid,
        ])->assertStatus(403);
    }

    public function test_accepted_product_and_rejected_recipe_same_uuid_via_api(): void
    {
        Storage::fake('local');
        Queue::fake();

        $branch = $this->createBranch(['status' => BranchStatus::ACTIVE]);
        $token = $branch->issueIngestToken();

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
                        'components' => 'invalid-not-array',
                    ],
                ],
            ],
        ];

        $response = $this->withToken($token)->postJson(route('api.v1.ingest.batches'), $envelope);

        $response->assertStatus(202)
            ->assertJson([
                'batch_uuid' => $batchUuid,
                'accepted' => [$sharedUuid],
            ]);

        $this->assertCount(1, $response->json('rejected'));
        $this->assertSame($sharedUuid, $response->json('rejected.0.uuid'));
        $this->assertSame('recipe', $response->json('rejected.0.type'));

        Queue::assertPushed(ProcessIngestBatch::class, function (ProcessIngestBatch $job) use ($sharedUuid) {
            return $job->acceptedUuids === [$sharedUuid]
                && $job->acceptedIndices === [0];
        });
    }

    public function test_valid_product_and_recipe_same_uuid_via_api(): void
    {
        Storage::fake('local');
        Queue::fake();

        $branch = $this->createBranch(['status' => BranchStatus::ACTIVE]);
        $token = $branch->issueIngestToken();

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

        $response = $this->withToken($token)->postJson(route('api.v1.ingest.batches'), $envelope);

        $response->assertStatus(202)
            ->assertJson([
                'batch_uuid' => $batchUuid,
                'accepted' => [$sharedUuid, $sharedUuid],
                'rejected' => [],
            ]);

        Queue::assertPushed(ProcessIngestBatch::class, function (ProcessIngestBatch $job) use ($sharedUuid) {
            return $job->acceptedUuids === [$sharedUuid, $sharedUuid]
                && $job->acceptedIndices === [0, 1];
        });
    }

    public function test_gzip_oversized_decompressed_payload_returns_413(): void
    {
        $branch = $this->createBranch(['status' => BranchStatus::ACTIVE]);
        $token = $branch->issueIngestToken();

        // Create an envelope that compresses small (<100KB) but decompresses to > 5 MiB
        $largeName = str_repeat('X', 6 * 1024 * 1024);
        $envelope = [
            'schema_version' => 1,
            'batch_uuid' => (string) Str::uuid(),
            'branch_uuid' => $branch->uuid,
            'pos_version' => '2.4.0',
            'sent_at' => now()->toIso8601String(),
            'seq_from' => 1,
            'seq_to' => 1,
            'documents' => [
                [
                    'type' => 'category',
                    'uuid' => (string) Str::uuid(),
                    'version' => 1,
                    'op' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'data' => [
                        'name' => $largeName,
                    ],
                ],
            ],
        ];

        $json = json_encode($envelope);
        $this->assertIsString($json);
        $this->assertGreaterThan(5 * 1024 * 1024, strlen($json));

        $gzipped = gzencode($json, 9);
        $this->assertIsString($gzipped);
        $this->assertLessThan(100 * 1024, strlen($gzipped)); // Highly compressed

        $server = $this->transformHeadersToServerVars([
            'Authorization' => 'Bearer '.$token,
            'Content-Type' => 'application/json',
            'Content-Encoding' => 'gzip',
        ]);

        $response = $this->call('POST', route('api.v1.ingest.batches'), [], [], [], $server, $gzipped);

        $response->assertStatus(413);
    }

    public function test_order_with_null_return_status_is_accepted(): void
    {
        Storage::fake('local');
        Queue::fake();

        $branch = $this->createBranch(['status' => BranchStatus::ACTIVE]);
        $token = $branch->issueIngestToken();

        $batchUuid = (string) Str::uuid();
        $orderUuid = (string) Str::uuid();
        $shiftUuid = (string) Str::uuid();
        $itemUuid = (string) Str::uuid();
        $productUuid = (string) Str::uuid();

        // Payload matching actual OrderBuilder output with return_status => null
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
                    'type' => 'order',
                    'uuid' => $orderUuid,
                    'version' => 1,
                    'op' => 'upsert',
                    'occurred_at' => '2026-10-29T21:14:03+03:00',
                    'data' => [
                        'number' => 'ORD-1001',
                        'shift_uuid' => $shiftUuid,
                        'business_date' => '2026-10-29',
                        'type' => 'dine_in',
                        'status' => 'completed',
                        'payment_status' => 'paid',
                        'return_status' => null, // genuine connector payload from OrderBuilder
                        'created_at' => '2026-10-29T21:14:03+03:00',
                        'cashier_uuid' => null,
                        'driver_uuid' => null,
                        'customer_hash' => null,
                        'region' => null,
                        'sub_total' => '200.00',
                        'discount' => '0.00',
                        'tax' => '28.00',
                        'service' => '24.00',
                        'total' => '252.00',
                        'profit' => '80.00',
                        'web_pos_diff' => '0.00',
                        'items' => [
                            [
                                'uuid' => $itemUuid,
                                'product_uuid' => $productUuid,
                                'product_ref' => 'PRD-1',
                                'quantity' => '2.000',
                                'price' => '100.00',
                                'unit_cost' => '60.00',
                                'item_discount' => '0.00',
                                'item_discount_type' => null,
                                'total' => '200.00',
                            ],
                        ],
                        'payments' => [
                            [
                                'method' => 'cash',
                                'amount' => '252.00',
                                'shift_uuid' => $shiftUuid,
                                'paid_at' => '2026-10-29T21:15:00+03:00',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->withToken($token)->postJson(route('api.v1.ingest.batches'), $envelope);

        $response->assertStatus(202)
            ->assertJson([
                'batch_uuid' => $batchUuid,
                'accepted' => [$orderUuid],
                'rejected' => [],
            ]);
    }

    public function test_empty_upsert_collections_allowed_for_recipe_and_inventory_day(): void
    {
        Storage::fake('local');
        Queue::fake();

        $branch = $this->createBranch(['status' => BranchStatus::ACTIVE]);
        $token = $branch->issueIngestToken();

        $batchUuid = (string) Str::uuid();
        $recipeUuid = (string) Str::uuid();
        $invUuid = (string) Str::uuid();

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
                    'type' => 'recipe',
                    'uuid' => $recipeUuid,
                    'version' => 1,
                    'op' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'data' => [
                        'product_uuid' => (string) Str::uuid(),
                        'components' => [], // Empty components collection
                    ],
                ],
                [
                    'type' => 'inventory_day',
                    'uuid' => $invUuid,
                    'version' => 1,
                    'op' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'data' => [
                        'date' => '2026-10-29',
                        'rows' => [], // Empty rows collection
                    ],
                ],
            ],
        ];

        $response = $this->withToken($token)->postJson(route('api.v1.ingest.batches'), $envelope);

        $response->assertStatus(202)
            ->assertJson([
                'batch_uuid' => $batchUuid,
                'accepted' => [$recipeUuid, $invUuid],
                'rejected' => [],
            ]);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function documentTypesProvider(): array
    {
        return array_map(fn (string $type) => [$type], DocumentRules::TYPES);
    }

    #[DataProvider('documentTypesProvider')]
    public function test_delete_documents_with_null_data_validate_for_all_types(string $type): void
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
                    'type' => $type,
                    'uuid' => $docUuid,
                    'version' => 1,
                    'op' => 'delete',
                    'occurred_at' => now()->toIso8601String(),
                    'data' => null,
                ],
            ],
        ];

        $response = $this->withToken($token)->postJson(route('api.v1.ingest.batches'), $envelope);

        $response->assertStatus(202)
            ->assertJson([
                'batch_uuid' => $batchUuid,
                'accepted' => [$docUuid],
                'rejected' => [],
            ]);
    }

    public function test_conflicting_retry_retains_original_body_and_result_without_overwrite(): void
    {
        Storage::fake('local');
        Queue::fake();

        $branch = $this->createBranch(['status' => BranchStatus::ACTIVE]);
        $token = $branch->issueIngestToken();

        $batchUuid = (string) Str::uuid();
        $doc1Uuid = (string) Str::uuid();

        // 1. Initial batch
        $envelope1 = [
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
                    'uuid' => $doc1Uuid,
                    'version' => 1,
                    'op' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'data' => ['name' => 'Original Category'],
                ],
            ],
        ];

        $first = $this->withToken($token)->postJson(route('api.v1.ingest.batches'), $envelope1);
        $first->assertStatus(202)
            ->assertJson([
                'batch_uuid' => $batchUuid,
                'accepted' => [$doc1Uuid],
            ]);

        $batch = IngestBatch::where('batch_uuid', $batchUuid)->firstOrFail();
        $storagePath = $batch->storage_path;
        $this->assertNotNull($storagePath);
        $originalStored = Storage::disk('local')->get($storagePath);
        $this->assertStringContainsString('Original Category', (string) $originalStored);

        // 2. Conflicting retry with different document data and UUID
        $doc2Uuid = (string) Str::uuid();
        $envelope2 = [
            'schema_version' => 1,
            'batch_uuid' => $batchUuid, // same UUID
            'branch_uuid' => $branch->uuid,
            'pos_version' => '2.4.0',
            'sent_at' => now()->toIso8601String(),
            'seq_from' => 1,
            'seq_to' => 1,
            'documents' => [
                [
                    'type' => 'category',
                    'uuid' => $doc2Uuid,
                    'version' => 2,
                    'op' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'data' => ['name' => 'Conflicting Attempted Overwrite'],
                ],
            ],
        ];

        $second = $this->withToken($token)->postJson(route('api.v1.ingest.batches'), $envelope2);
        $second->assertStatus(409)
            ->assertJson([
                'batch_uuid' => $batchUuid,
                'accepted' => [$doc1Uuid], // Must retain original accepted
            ]);

        // Verify storage file was NOT overwritten!
        $currentStored = Storage::disk('local')->get($storagePath);
        $this->assertSame($originalStored, $currentStored);
        $this->assertStringNotContainsString('Conflicting Attempted Overwrite', (string) $currentStored);
    }

    public function test_storage_failure_not_acknowledged_as_accepted(): void
    {
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
                    'data' => ['name' => 'Category'],
                ],
            ],
        ];

        // Simulate storage disk failure by mocking put() to return false
        $diskMock = \Mockery::mock(Filesystem::class);
        $diskMock->shouldReceive('put')->andReturn(false);
        Storage::set('local', $diskMock);

        $response = $this->withToken($token)->postJson(route('api.v1.ingest.batches'), $envelope);

        // Must NOT be 202 accepted!
        $this->assertNotSame(202, $response->status());
        $response->assertStatus(500);

        // No batch record in DB
        $this->assertDatabaseMissing('ingest_batches', [
            'batch_uuid' => $batchUuid,
        ]);

        // No job dispatched
        Queue::assertNothingPushed();
    }

    public function test_retry_after_dispatch_failure_re_enqueues_batch_and_returns_409(): void
    {
        Storage::fake('local');

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
                    'data' => ['name' => 'Initial Category'],
                ],
            ],
        ];

        // 1. First attempt: simulate a crash / failure during job dispatch (e.g. queue broker down)
        $originalDispatcher = $this->app->make(Dispatcher::class);
        $dispatcherMock = \Mockery::mock(Dispatcher::class);
        $dispatcherMock->shouldReceive('dispatch')->andThrow(new \RuntimeException('Simulated queue broker failure'));
        $this->app->instance(Dispatcher::class, $dispatcherMock);

        // First attempt fails during dispatch and returns 500
        $response1 = $this->withToken($token)->postJson(route('api.v1.ingest.batches'), $envelope);
        $response1->assertStatus(500);
        $this->assertStringNotContainsString('Simulated queue broker failure', (string) $response1->json('message'));

        // Batch DB record is committed with status received
        $this->assertDatabaseHas('ingest_batches', [
            'batch_uuid' => $batchUuid,
            'status' => 'received',
        ]);

        // 2. Second attempt (retry): queue is now healthy
        $this->app->instance(Dispatcher::class, $originalDispatcher);
        Queue::fake();

        $response2 = $this->withToken($token)->postJson(route('api.v1.ingest.batches'), $envelope);

        // Retry returns 409 and retains original result
        $response2->assertStatus(409)
            ->assertJson([
                'batch_uuid' => $batchUuid,
                'accepted' => [$docUuid],
                'rejected' => [],
            ]);

        // CRITICAL: The job MUST be enqueued on retry so work is not lost!
        Queue::assertPushed(ProcessIngestBatch::class, function (ProcessIngestBatch $job) use ($batchUuid, $docUuid) {
            $batch = IngestBatch::where('batch_uuid', $batchUuid)->first();

            return $job->batchId === $batch?->id
                && $job->acceptedUuids === [$docUuid];
        });
    }

    public function test_internal_exception_message_is_not_exposed_to_caller(): void
    {
        $branch = $this->createBranch(['status' => BranchStatus::ACTIVE]);
        $token = $branch->issueIngestToken();

        $batchUuid = (string) Str::uuid();

        $envelope = [
            'schema_version' => 1,
            'batch_uuid' => $batchUuid,
            'branch_uuid' => $branch->uuid,
            'pos_version' => '2.4.0',
            'sent_at' => now()->toIso8601String(),
            'seq_from' => 1,
            'seq_to' => 1,
            'documents' => [],
        ];

        // Simulate internal storage failure with sensitive internal path message
        $diskMock = \Mockery::mock(Filesystem::class);
        $diskMock->shouldReceive('put')->andThrow(new \RuntimeException('Sensitive DB connection failed at /var/secret/path:5432'));
        Storage::set('local', $diskMock);

        $response = $this->withToken($token)->postJson(route('api.v1.ingest.batches'), $envelope);

        $response->assertStatus(500);
        $this->assertStringNotContainsString('Sensitive DB connection', (string) $response->getContent());
        $this->assertStringNotContainsString('/var/secret', (string) $response->getContent());
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function emptyCollectionsProvider(): array
    {
        return [
            'empty recipe components' => [
                'recipe',
                [
                    'product_uuid' => 'a0000000-0000-0000-0000-000000000001',
                    'components' => [],
                ],
            ],
            'empty inventory_day rows' => [
                'inventory_day',
                [
                    'date' => '2026-10-29',
                    'rows' => [],
                ],
            ],
            'empty order payments' => [
                'order',
                [
                    'number' => 'ORD-999',
                    'shift_uuid' => 'b0000000-0000-0000-0000-000000000001',
                    'business_date' => '2026-10-29',
                    'type' => 'dine_in',
                    'status' => 'completed',
                    'payment_status' => 'unpaid',
                    'return_status' => null,
                    'created_at' => '2026-10-29T10:00:00+03:00',
                    'sub_total' => '100.00',
                    'discount' => '0.00',
                    'tax' => '0.00',
                    'service' => '0.00',
                    'total' => '100.00',
                    'profit' => '50.00',
                    'web_pos_diff' => '0.00',
                    'items' => [
                        [
                            'uuid' => 'c0000000-0000-0000-0000-000000000001',
                            'product_uuid' => 'a0000000-0000-0000-0000-000000000001',
                            'quantity' => '1.000',
                            'price' => '100.00',
                            'unit_cost' => '50.00',
                            'item_discount' => '0.00',
                            'total' => '100.00',
                        ],
                    ],
                    'payments' => [],
                ],
            ],
            'empty order_return refunds' => [
                'order_return',
                [
                    'order_uuid' => 'd0000000-0000-0000-0000-000000000001',
                    'business_date' => '2026-10-29',
                    'total_refund' => '0.00',
                    'items' => [
                        [
                            'order_item_uuid' => 'c0000000-0000-0000-0000-000000000001',
                            'quantity' => '1.000',
                            'refund_amount' => '0.00',
                        ],
                    ],
                    'refunds' => [],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('emptyCollectionsProvider')]
    public function test_empty_upsert_collections_parameterized(string $type, array $data): void
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
                    'type' => $type,
                    'uuid' => $docUuid,
                    'version' => 1,
                    'op' => 'upsert',
                    'occurred_at' => now()->toIso8601String(),
                    'data' => $data,
                ],
            ],
        ];

        $response = $this->withToken($token)->postJson(route('api.v1.ingest.batches'), $envelope);

        $response->assertStatus(202)
            ->assertJson([
                'batch_uuid' => $batchUuid,
                'accepted' => [$docUuid],
                'rejected' => [],
            ]);
    }
}
