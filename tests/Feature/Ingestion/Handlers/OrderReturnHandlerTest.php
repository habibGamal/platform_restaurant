<?php

namespace Tests\Feature\Ingestion\Handlers;

use App\Domain\Ingestion\Handlers\OrderReturnHandler;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Warehouse\WhOrderItem;
use App\Models\Warehouse\WhOrderReturn;
use App\Models\Warehouse\WhOrderReturnItem;
use App\Models\Warehouse\WhRefund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderReturnHandlerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private OrderReturnHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create();
        $restaurant = Restaurant::factory()->create(['organization_id' => $org->id]);
        $this->branch = Branch::factory()->create([
            'organization_id' => $org->id,
            'restaurant_id' => $restaurant->id,
        ]);

        $this->handler = new OrderReturnHandler;
    }

    public function test_insert_order_return_computes_returned_cogs_from_referenced_items(): void
    {
        // Seed referenced wh_order_items
        WhOrderItem::create([
            'organization_id' => $this->branch->organization_id,
            'branch_id' => $this->branch->id,
            'order_uuid' => '01930f2a-order-uuid-1',
            'uuid' => '01930f2a-order-item-1',
            'product_uuid' => '01930f2a-prod-1',
            'product_ref' => 'REF-1',
            'quantity' => '5.000',
            'price' => '100.00',
            'unit_cost' => '30.00',
            'item_discount' => '0.00',
            'total' => '500.00',
        ]);

        // Return 2 of item-1 (unit_cost 30.00 => 60.00) + 1 of unknown item (unit_cost 0 => 0.00)
        $document = [
            'type' => 'order_return',
            'uuid' => '01930f2a-return-uuid-1',
            'version' => 10,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T14:00:00+02:00',
            'data' => [
                'order_uuid' => '01930f2a-order-uuid-1',
                'shift_uuid' => '01930f2a-shift-1',
                'business_date' => '2026-10-29',
                'total_refund' => '250.00',
                'reason' => 'Defective packaging',
                'reverse_stock' => true,
                'items' => [
                    [
                        'order_item_uuid' => '01930f2a-order-item-1',
                        'quantity' => '2.000',
                        'refund_amount' => '200.00',
                    ],
                    [
                        'order_item_uuid' => '01930f2a-unknown-item',
                        'quantity' => '1.000',
                        'refund_amount' => '50.00',
                    ],
                ],
                'refunds' => [
                    [
                        'method' => 'cash',
                        'amount' => '250.00',
                    ],
                ],
            ],
        ];

        $result = $this->handler->handle($this->branch, $document);

        $this->assertTrue($result->applied);
        $this->assertSame(['2026-10-29'], $result->dirtyDates);
        $this->assertFalse($result->dayClosed);

        $return = WhOrderReturn::where('uuid', $document['uuid'])->first();
        $this->assertNotNull($return);
        $this->assertSame('01930f2a-order-uuid-1', $return->order_uuid);
        $this->assertSame('250.00', (string) $return->total_refund);
        $this->assertTrue($return->reverse_stock);
        // returned_cogs: 2.000 * 30.00 + 1.000 * 0 = 60.00
        $this->assertSame('60.00', (string) $return->returned_cogs);

        $this->assertCount(2, WhOrderReturnItem::where('return_uuid', $return->uuid)->get());
        $this->assertCount(1, WhRefund::where('return_uuid', $return->uuid)->get());
    }

    public function test_update_higher_version_replaces_children(): void
    {
        $uuid = '01930f2a-return-uuid-2';

        $v1Doc = [
            'type' => 'order_return',
            'uuid' => $uuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => [
                'order_uuid' => '01930f2a-order-1',
                'business_date' => '2026-10-29',
                'total_refund' => '100.00',
                'items' => [
                    [
                        'order_item_uuid' => 'old-item',
                        'quantity' => '1.000',
                        'refund_amount' => '100.00',
                    ],
                ],
                'refunds' => [],
            ],
        ];
        $this->handler->handle($this->branch, $v1Doc);

        $v2Doc = [
            'type' => 'order_return',
            'uuid' => $uuid,
            'version' => 2,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:30:00+02:00',
            'data' => [
                'order_uuid' => '01930f2a-order-1',
                'business_date' => '2026-10-29',
                'total_refund' => '150.00',
                'items' => [
                    [
                        'order_item_uuid' => 'new-item-1',
                        'quantity' => '1.000',
                        'refund_amount' => '75.00',
                    ],
                    [
                        'order_item_uuid' => 'new-item-2',
                        'quantity' => '1.000',
                        'refund_amount' => '75.00',
                    ],
                ],
                'refunds' => [
                    ['method' => 'cash', 'amount' => '150.00'],
                ],
            ],
        ];
        $result = $this->handler->handle($this->branch, $v2Doc);

        $this->assertTrue($result->applied);
        $return = WhOrderReturn::where('uuid', $uuid)->first();
        $this->assertSame(2, $return->source_version);
        $this->assertSame('150.00', (string) $return->total_refund);

        $items = WhOrderReturnItem::where('return_uuid', $uuid)->get();
        $this->assertCount(2, $items);
        $this->assertFalse($items->contains('order_item_uuid', 'old-item'));
        $this->assertTrue($items->contains('order_item_uuid', 'new-item-1'));
    }

    public function test_lower_or_equal_version_skipped(): void
    {
        $uuid = '01930f2a-return-uuid-3';

        $this->handler->handle($this->branch, [
            'type' => 'order_return',
            'uuid' => $uuid,
            'version' => 5,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => [
                'order_uuid' => '01930f2a-order-1',
                'business_date' => '2026-10-29',
                'total_refund' => '50.00',
            ],
        ]);

        $resEqual = $this->handler->handle($this->branch, [
            'type' => 'order_return',
            'uuid' => $uuid,
            'version' => 5,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['order_uuid' => 'changed', 'total_refund' => '999.00'],
        ]);
        $this->assertFalse($resEqual->applied);

        $resLower = $this->handler->handle($this->branch, [
            'type' => 'order_return',
            'uuid' => $uuid,
            'version' => 4,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['order_uuid' => 'changed', 'total_refund' => '999.00'],
        ]);
        $this->assertFalse($resLower->applied);
    }

    public function test_delete_tombstone(): void
    {
        $uuid = '01930f2a-return-uuid-4';

        $this->handler->handle($this->branch, [
            'type' => 'order_return',
            'uuid' => $uuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => [
                'order_uuid' => '01930f2a-order-1',
                'business_date' => '2026-10-29',
                'total_refund' => '50.00',
                'items' => [
                    ['order_item_uuid' => 'item-1', 'quantity' => '1.000', 'refund_amount' => '50.00'],
                ],
            ],
        ]);

        $resDelete = $this->handler->handle($this->branch, [
            'type' => 'order_return',
            'uuid' => $uuid,
            'version' => 2,
            'op' => 'delete',
            'occurred_at' => '2026-10-29T11:00:00+02:00',
            'data' => null,
        ]);

        $this->assertTrue($resDelete->applied);
        $this->assertSame(['2026-10-29'], $resDelete->dirtyDates);

        $trashed = WhOrderReturn::withTrashed()->where('uuid', $uuid)->first();
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame(2, $trashed->source_version);
        // Children kept
        $this->assertCount(1, WhOrderReturnItem::where('return_uuid', $uuid)->get());
    }
}
