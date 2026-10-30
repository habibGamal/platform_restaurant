<?php

namespace Tests\Feature\Ingestion\Api;

use App\Jobs\RecomputeBranchDay;
use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderWebPosDiffTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{string, bool, mixed, bool}> */
    public static function orderValues(): iterable
    {
        foreach (['dine_in', 'takeaway', 'delivery', 'companies', 'talabat'] as $type) {
            yield "$type missing" => [$type, false, null, true];
            yield "$type null" => [$type, true, null, true];
        }

        foreach (['web_delivery', 'web_takeaway'] as $type) {
            yield "$type missing" => [$type, false, null, false];
            yield "$type null" => [$type, true, null, false];
            yield "$type zero" => [$type, true, '0.00', true];
            yield "$type difference" => [$type, true, '-12.50', true];
        }

        yield 'regular numeric difference preserved' => ['dine_in', true, '5.25', true];
        yield 'regular malformed difference' => ['dine_in', true, 'invalid', false];
        yield 'web malformed difference' => ['web_delivery', true, 'invalid', false];
    }

    #[DataProvider('orderValues')]
    public function test_order_difference_validation_and_warehouse_storage(string $type, bool $includeDifference, mixed $difference, bool $accepted): void
    {
        Storage::fake('local');
        Queue::fake([RecomputeBranchDay::class]);
        config(['queue.default' => 'sync']);
        $branch = Branch::factory()->active()->create();
        $uuid = (string) Str::uuid();
        $data = [
            'number' => 'POS-1',
            'shift_uuid' => (string) Str::uuid(),
            'business_date' => '2026-10-30',
            'type' => $type,
            'status' => 'completed',
            'payment_status' => 'paid',
            'return_status' => null,
            'created_at' => '2026-10-30T12:00:00+02:00',
            'sub_total' => '100.00',
            'discount' => '0.00',
            'tax' => '0.00',
            'service' => '0.00',
            'total' => '100.00',
            'profit' => '60.00',
            'items' => [[
                'uuid' => (string) Str::uuid(),
                'product_uuid' => (string) Str::uuid(),
                'quantity' => '1.000',
                'price' => '100.00',
                'unit_cost' => '40.00',
                'item_discount' => '0.00',
                'total' => '100.00',
            ]],
            'payments' => [],
        ];

        if ($includeDifference) {
            $data['web_pos_diff'] = $difference;
        }

        $response = $this->withToken($branch->issueIngestToken())->postJson(route('api.v1.ingest.batches'), [
            'schema_version' => 1,
            'batch_uuid' => (string) Str::uuid(),
            'branch_uuid' => $branch->uuid,
            'pos_version' => '1.0.0',
            'sent_at' => now()->toIso8601String(),
            'seq_from' => 1,
            'seq_to' => 1,
            'documents' => [[
                'type' => 'order',
                'uuid' => $uuid,
                'version' => 1,
                'op' => 'upsert',
                'occurred_at' => now()->toIso8601String(),
                'data' => $data,
            ]],
        ])->assertStatus(202);

        if ($accepted) {
            $response->assertJsonPath('accepted', [$uuid])->assertJsonPath('rejected', []);
            $this->assertDatabaseHas('wh_orders', [
                'branch_id' => $branch->id,
                'uuid' => $uuid,
                'web_pos_diff' => $difference,
            ]);
            $this->assertDatabaseCount('wh_order_items', 1);
            $this->assertDatabaseCount('ingest_rejections', 0);

            return;
        }

        $response->assertJsonPath('accepted', [])->assertJsonPath('rejected.0.uuid', $uuid);
        $this->assertStringContainsString('web pos diff', $response->json('rejected.0.reason'));
        $this->assertDatabaseMissing('wh_orders', ['uuid' => $uuid]);
        $this->assertDatabaseCount('ingest_rejections', 1);
    }
}
