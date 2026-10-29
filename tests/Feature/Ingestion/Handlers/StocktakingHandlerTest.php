<?php

namespace Tests\Feature\Ingestion\Handlers;

use App\Domain\Ingestion\Handlers\StocktakingHandler;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Warehouse\WhStocktaking;
use App\Models\Warehouse\WhStocktakingItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StocktakingHandlerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private StocktakingHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create();
        $restaurant = Restaurant::factory()->create(['organization_id' => $org->id]);
        $this->branch = Branch::factory()->create([
            'organization_id' => $org->id,
            'restaurant_id' => $restaurant->id,
        ]);

        $this->handler = new StocktakingHandler;
    }

    public function test_insert_stocktaking_computes_variance_cost(): void
    {
        // Item 1: stock 10, real 8, price 25 => diff +2 => variance +50.00
        // Item 2: stock 5, real 6, price 10 => diff -1 => variance -10.00
        // Total variance cost: 50.00 - 10.00 = 40.00
        $document = [
            'type' => 'stocktaking',
            'uuid' => '01930f2a-stock-uuid-1',
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T16:00:00+02:00',
            'data' => [
                'business_date' => '2026-10-29',
                'closed_at' => '2026-10-29T16:00:00+02:00',
                'total' => '310.00',
                'items' => [
                    [
                        'product_uuid' => '01930f2a-prod-1',
                        'stock_quantity' => '10.000',
                        'real_quantity' => '8.000',
                        'price' => '25.00',
                        'total' => '200.00',
                    ],
                    [
                        'product_uuid' => '01930f2a-prod-2',
                        'stock_quantity' => '5.000',
                        'real_quantity' => '6.000',
                        'price' => '10.00',
                        'total' => '60.00',
                    ],
                ],
            ],
        ];

        $result = $this->handler->handle($this->branch, $document);

        $this->assertTrue($result->applied);
        $this->assertSame(['2026-10-29'], $result->dirtyDates);

        $stocktaking = WhStocktaking::where('uuid', $document['uuid'])->first();
        $this->assertNotNull($stocktaking);
        $this->assertSame('40.00', (string) $stocktaking->variance_cost);
        $this->assertCount(2, WhStocktakingItem::where('stocktaking_uuid', $stocktaking->uuid)->get());
    }

    public function test_update_higher_version_replaces_items(): void
    {
        $uuid = '01930f2a-stock-uuid-2';

        $this->handler->handle($this->branch, [
            'type' => 'stocktaking',
            'uuid' => $uuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => [
                'business_date' => '2026-10-29',
                'total' => '100.00',
                'items' => [
                    ['product_uuid' => 'p1', 'stock_quantity' => '5.000', 'real_quantity' => '5.000', 'price' => '20.00', 'total' => '100.00'],
                ],
            ],
        ]);

        $resUpdate = $this->handler->handle($this->branch, [
            'type' => 'stocktaking',
            'uuid' => $uuid,
            'version' => 2,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T11:00:00+02:00',
            'data' => [
                'business_date' => '2026-10-29',
                'total' => '200.00',
                'items' => [
                    ['product_uuid' => 'p2', 'stock_quantity' => '10.000', 'real_quantity' => '7.000', 'price' => '20.00', 'total' => '140.00'],
                ],
            ],
        ]);

        $this->assertTrue($resUpdate->applied);
        $items = WhStocktakingItem::where('stocktaking_uuid', $uuid)->get();
        $this->assertCount(1, $items);
        $this->assertSame('p2', $items->first()->product_uuid);
    }

    public function test_delete_tombstone(): void
    {
        $uuid = '01930f2a-stock-uuid-3';

        $this->handler->handle($this->branch, [
            'type' => 'stocktaking',
            'uuid' => $uuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['business_date' => '2026-10-29', 'total' => '100.00'],
        ]);

        $resDelete = $this->handler->handle($this->branch, [
            'type' => 'stocktaking',
            'uuid' => $uuid,
            'version' => 2,
            'op' => 'delete',
            'occurred_at' => '2026-10-29T11:00:00+02:00',
            'data' => null,
        ]);

        $this->assertTrue($resDelete->applied);
        $trashed = WhStocktaking::withTrashed()->where('uuid', $uuid)->first();
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame(2, $trashed->source_version);
    }
}
