<?php

declare(strict_types=1);

namespace Tests\Feature\Menu;

use App\Domain\Ingestion\Contracts\DocumentHandlerRegistry;
use App\Jobs\ClassifyItemRoles;
use App\Jobs\ProcessIngestBatch;
use App\Models\Branch;
use App\Models\IngestBatch;
use App\Models\Organization;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogGrowthDispatchTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();

        $org = Organization::factory()->create();
        $restaurant = Restaurant::factory()->create(['organization_id' => $org->id]);
        $this->branch = Branch::factory()->create([
            'organization_id' => $org->id,
            'restaurant_id' => $restaurant->id,
            'last_seq' => 0,
        ]);
    }

    /**
     * Process one batch holding the given documents with the real handlers.
     *
     * @param  list<array{type: string, uuid: string, version: int, name: string}>  $docs
     */
    private function ingest(array $docs): void
    {
        $batchUuid = (string) Str::uuid();
        $documents = array_map(fn (array $d): array => [
            'type' => $d['type'],
            'uuid' => $d['uuid'],
            'version' => $d['version'],
            'op' => 'upsert',
            'occurred_at' => now()->toIso8601String(),
            'data' => ['name' => $d['name']] + ($d['type'] === 'product' ? ['type' => 'manufactured', 'price' => '10.00', 'cost' => '4.00'] : []),
        ], $docs);
        $uuids = array_column($docs, 'uuid');

        $path = "ingest/{$this->branch->organization_id}/{$this->branch->id}/2026/10/{$batchUuid}.json";
        Storage::disk('local')->put($path, (string) json_encode([
            'schema_version' => 1,
            'batch_uuid' => $batchUuid,
            'branch_uuid' => $this->branch->uuid,
            'pos_version' => '2.4.0',
            'sent_at' => now()->toIso8601String(),
            'seq_from' => 1,
            'seq_to' => 100,
            'documents' => $documents,
        ]));

        $batch = IngestBatch::create([
            'organization_id' => $this->branch->organization_id,
            'branch_id' => $this->branch->id,
            'batch_uuid' => $batchUuid,
            'schema_version' => 1,
            'pos_version' => '2.4.0',
            'seq_from' => 1,
            'seq_to' => 100,
            'docs_count' => count($docs),
            'accepted_count' => count($docs),
            'rejected_count' => 0,
            'status' => 'received',
            'result' => ['batch_uuid' => $batchUuid, 'accepted' => $uuids, 'rejected' => []],
            'storage_path' => $path,
            'received_at' => now(),
        ]);

        (new ProcessIngestBatch($batch->id, $uuids, $this->branch->id))->handle(app(DocumentHandlerRegistry::class));
    }

    public function test_a_batch_with_new_catalog_rows_queues_one_classification_for_the_organization(): void
    {
        $this->ingest([
            ['type' => 'category', 'uuid' => (string) Str::uuid(), 'version' => 1, 'name' => 'Cat 1'],
            ['type' => 'category', 'uuid' => (string) Str::uuid(), 'version' => 2, 'name' => 'Cat 2'],
            ['type' => 'product', 'uuid' => (string) Str::uuid(), 'version' => 3, 'name' => 'Item 1'],
        ]);

        Queue::assertPushed(ClassifyItemRoles::class, 1);
        Queue::assertPushed(ClassifyItemRoles::class, fn (ClassifyItemRoles $job) => $job->organizationId === $this->branch->organization_id);
    }

    public function test_updating_existing_catalog_rows_queues_nothing(): void
    {
        $productUuid = (string) Str::uuid();
        $this->ingest([['type' => 'product', 'uuid' => $productUuid, 'version' => 1, 'name' => 'Item 1']]);
        Queue::assertPushed(ClassifyItemRoles::class, 1);

        $this->ingest([['type' => 'product', 'uuid' => $productUuid, 'version' => 2, 'name' => 'Item 1 renamed']]);

        Queue::assertPushed(ClassifyItemRoles::class, 1);
    }
}
