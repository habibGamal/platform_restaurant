<?php

namespace Tests\Feature\Ingestion\Handlers;

use App\Domain\Ingestion\Handlers\WasteHandler;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Warehouse\WhWaste;
use App\Models\Warehouse\WhWasteItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WasteHandlerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private WasteHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create();
        $restaurant = Restaurant::factory()->create(['organization_id' => $org->id]);
        $this->branch = Branch::factory()->create([
            'organization_id' => $org->id,
            'restaurant_id' => $restaurant->id,
        ]);

        $this->handler = new WasteHandler;
    }

    public function test_insert_waste(): void
    {
        $document = [
            'type' => 'waste',
            'uuid' => '01930f2a-waste-uuid-1',
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T15:00:00+02:00',
            'data' => [
                'business_date' => '2026-10-29',
                'closed_at' => '2026-10-29T15:00:00+02:00',
                'total' => '75.00',
                'items' => [
                    [
                        'product_uuid' => '01930f2a-prod-1',
                        'quantity' => '3.000',
                        'price' => '25.00',
                        'total' => '75.00',
                    ],
                ],
            ],
        ];

        $result = $this->handler->handle($this->branch, $document);

        $this->assertTrue($result->applied);
        $this->assertSame(['2026-10-29'], $result->dirtyDates);

        $waste = WhWaste::where('uuid', $document['uuid'])->first();
        $this->assertNotNull($waste);
        $this->assertSame('75.00', (string) $waste->total);

        $this->assertCount(1, WhWasteItem::where('waste_uuid', $waste->uuid)->get());
    }

    public function test_update_higher_version_replaces_items(): void
    {
        $uuid = '01930f2a-waste-uuid-2';

        $this->handler->handle($this->branch, [
            'type' => 'waste',
            'uuid' => $uuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => [
                'business_date' => '2026-10-29',
                'total' => '20.00',
                'items' => [
                    ['product_uuid' => 'p1', 'quantity' => '1.000', 'price' => '20.00', 'total' => '20.00'],
                ],
            ],
        ]);

        $resUpdate = $this->handler->handle($this->branch, [
            'type' => 'waste',
            'uuid' => $uuid,
            'version' => 2,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T11:00:00+02:00',
            'data' => [
                'business_date' => '2026-10-29',
                'total' => '40.00',
                'items' => [
                    ['product_uuid' => 'p2', 'quantity' => '2.000', 'price' => '20.00', 'total' => '40.00'],
                ],
            ],
        ]);

        $this->assertTrue($resUpdate->applied);
        $items = WhWasteItem::where('waste_uuid', $uuid)->get();
        $this->assertCount(1, $items);
        $this->assertSame('p2', $items->first()->product_uuid);
    }

    public function test_delete_tombstone(): void
    {
        $uuid = '01930f2a-waste-uuid-3';

        $this->handler->handle($this->branch, [
            'type' => 'waste',
            'uuid' => $uuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['business_date' => '2026-10-29', 'total' => '20.00'],
        ]);

        $resDelete = $this->handler->handle($this->branch, [
            'type' => 'waste',
            'uuid' => $uuid,
            'version' => 2,
            'op' => 'delete',
            'occurred_at' => '2026-10-29T11:00:00+02:00',
            'data' => null,
        ]);

        $this->assertTrue($resDelete->applied);
        $trashed = WhWaste::withTrashed()->where('uuid', $uuid)->first();
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame(2, $trashed->source_version);
    }
}
