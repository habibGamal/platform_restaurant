<?php

namespace Tests\Feature\Ingestion\Api;

use App\Domain\Tenancy\PairingCodeService;
use App\Enums\BranchStatus;
use App\Jobs\ProcessIngestBatch;
use App\Jobs\RecomputeBranchDay;
use App\Models\Branch;
use App\Models\IngestBatch;
use App\Models\Organization;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class EndToEndIngestTest extends TestCase
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
        ], $attributes));
    }

    public function test_end_to_end_pair_gzip_ingest_process_and_recompute(): void
    {
        // Fake queue only for RecomputeBranchDay so ProcessIngestBatch runs on sync queue
        Queue::fake([RecomputeBranchDay::class]);

        // 1. Create a pending branch and pair it
        $branch = $this->createBranch([
            'status' => BranchStatus::PENDING,
            'pos_version' => null,
        ]);

        $code = PairingCodeService::generate($branch);

        $pairResponse = $this->postJson(route('api.v1.pair'), [
            'code' => $code,
            'pos_version' => '2.4.0',
        ]);

        $pairResponse->assertStatus(200);
        $token = $pairResponse->json('token');
        $this->assertNotEmpty($token);

        $branch->refresh();
        $this->assertSame(BranchStatus::ACTIVE, $branch->status);

        // 2. Build a gzip batch containing a real order document matching contracts.md
        $batchUuid = (string) Str::uuid();
        $orderUuid = (string) Str::uuid();
        $itemUuid = (string) Str::uuid();
        $productUuid = (string) Str::uuid();
        $shiftUuid = (string) Str::uuid();

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
                    'type' => 'order',
                    'uuid' => $orderUuid,
                    'version' => 101,
                    'op' => 'upsert',
                    'occurred_at' => '2026-10-29T21:14:03+03:00',
                    'data' => [
                        'number' => 'ORD-1001',
                        'shift_uuid' => $shiftUuid,
                        'business_date' => '2026-10-29',
                        'type' => 'dine_in',
                        'status' => 'completed',
                        'payment_status' => 'paid',
                        'return_status' => 'none',
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

        $json = json_encode($envelope);
        $this->assertIsString($json);
        $compressed = gzencode($json);
        $this->assertIsString($compressed);

        $server = $this->transformHeadersToServerVars([
            'Authorization' => 'Bearer '.$token,
            'Content-Encoding' => 'gzip',
            'Content-Type' => 'application/json',
        ]);

        $ingestResponse = $this->call('POST', route('api.v1.ingest.batches'), [], [], [], $server, $compressed);

        $ingestResponse->assertStatus(202)
            ->assertJson([
                'batch_uuid' => $batchUuid,
                'accepted' => [$orderUuid],
                'rejected' => [],
            ]);

        $batch = IngestBatch::where('batch_uuid', $batchUuid)->firstOrFail();

        // 5. Assert wh_orders row exists
        $this->assertDatabaseHas('wh_orders', [
            'organization_id' => $branch->organization_id,
            'branch_id' => $branch->id,
            'uuid' => $orderUuid,
            'number' => 'ORD-1001',
            'business_date' => '2026-10-29',
            'total' => '252.00',
        ]);

        $this->assertDatabaseHas('wh_order_items', [
            'organization_id' => $branch->organization_id,
            'branch_id' => $branch->id,
            'uuid' => $itemUuid,
            'product_uuid' => $productUuid,
            'quantity' => '2.000',
            'total' => '200.00',
        ]);

        $this->assertDatabaseHas('wh_payments', [
            'organization_id' => $branch->organization_id,
            'branch_id' => $branch->id,
            'method' => 'cash',
            'amount' => '252.00',
        ]);

        // 6. Assert RecomputeBranchDay was dispatched with delay for the dirty date
        Queue::assertPushed(RecomputeBranchDay::class, function (RecomputeBranchDay $recomputeJob) use ($branch) {
            return $recomputeJob->branchId === $branch->id
                && $recomputeJob->businessDate === '2026-10-29'
                && $recomputeJob->delay !== null;
        });

        // 7. Assert batch marked as processed
        $batch->refresh();
        $this->assertSame('processed', $batch->status);
        $this->assertNotNull($batch->processed_at);

        $branch->refresh();
        $this->assertSame(101, $branch->last_seq);
    }
}
