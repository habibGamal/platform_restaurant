<?php

namespace Tests\Feature\Ingestion\Handlers;

use App\Domain\Ingestion\Handlers\InventoryDayHandler;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Warehouse\WhInventoryDay;
use App\Models\Warehouse\WhInventoryDayRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryDayHandlerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private InventoryDayHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create();
        $restaurant = Restaurant::factory()->create(['organization_id' => $org->id]);
        $this->branch = Branch::factory()->create([
            'organization_id' => $org->id,
            'restaurant_id' => $restaurant->id,
        ]);

        $this->handler = new InventoryDayHandler;
    }

    public function test_insert_inventory_day_sets_day_closed_true(): void
    {
        $document = [
            'type' => 'inventory_day',
            'uuid' => '01930f2a-inv-uuid-1',
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T23:59:00+02:00',
            'data' => [
                'date' => '2026-10-29',
                'rows' => [
                    [
                        'product_uuid' => '01930f2a-prod-1',
                        'start_qty' => '10.000',
                        'incoming_qty' => '5.000',
                        'sales_qty' => '3.000',
                        'return_sales_qty' => '0.000',
                        'waste_qty' => '1.000',
                        'end_qty' => '11.000',
                    ],
                ],
            ],
        ];

        $result = $this->handler->handle($this->branch, $document);

        $this->assertTrue($result->applied);
        $this->assertTrue($result->dayClosed);
        $this->assertSame(['2026-10-29'], $result->dirtyDates);

        $invDay = WhInventoryDay::where('uuid', $document['uuid'])->first();
        $this->assertNotNull($invDay);
        $this->assertSame('2026-10-29', $invDay->business_date->format('Y-m-d'));

        $row = WhInventoryDayRow::where('inventory_day_uuid', $invDay->uuid)->first();
        $this->assertNotNull($row);
        $this->assertSame('2026-10-29', $row->business_date->format('Y-m-d'));
        $this->assertSame('10.000', (string) $row->start_qty);
        $this->assertSame('11.000', (string) $row->end_qty);
    }

    public function test_update_higher_version_replaces_rows(): void
    {
        $uuid = '01930f2a-inv-uuid-2';

        $this->handler->handle($this->branch, [
            'type' => 'inventory_day',
            'uuid' => $uuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T23:00:00+02:00',
            'data' => [
                'date' => '2026-10-29',
                'rows' => [
                    ['product_uuid' => 'p1', 'start_qty' => '1.000', 'end_qty' => '1.000'],
                ],
            ],
        ]);

        $resUpdate = $this->handler->handle($this->branch, [
            'type' => 'inventory_day',
            'uuid' => $uuid,
            'version' => 2,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T23:30:00+02:00',
            'data' => [
                'date' => '2026-10-29',
                'rows' => [
                    ['product_uuid' => 'p2', 'start_qty' => '2.000', 'end_qty' => '2.000'],
                ],
            ],
        ]);

        $this->assertTrue($resUpdate->applied);
        $rows = WhInventoryDayRow::where('inventory_day_uuid', $uuid)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('p2', $rows->first()->product_uuid);
    }

    public function test_delete_tombstone(): void
    {
        $uuid = '01930f2a-inv-uuid-3';

        $this->handler->handle($this->branch, [
            'type' => 'inventory_day',
            'uuid' => $uuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T23:00:00+02:00',
            'data' => [
                'date' => '2026-10-29',
                'rows' => [],
            ],
        ]);

        $resDelete = $this->handler->handle($this->branch, [
            'type' => 'inventory_day',
            'uuid' => $uuid,
            'version' => 2,
            'op' => 'delete',
            'occurred_at' => '2026-10-29T23:30:00+02:00',
            'data' => null,
        ]);

        $this->assertTrue($resDelete->applied);
        $trashed = WhInventoryDay::withTrashed()->where('uuid', $uuid)->first();
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame(2, $trashed->source_version);
    }
}
