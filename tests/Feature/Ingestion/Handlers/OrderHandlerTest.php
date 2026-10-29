<?php

namespace Tests\Feature\Ingestion\Handlers;

use App\Domain\Ingestion\Handlers\OrderHandler;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Warehouse\WhOrder;
use App\Models\Warehouse\WhOrderItem;
use App\Models\Warehouse\WhPayment;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderHandlerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private OrderHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create();
        $restaurant = Restaurant::factory()->create(['organization_id' => $org->id]);
        $this->branch = Branch::factory()->create([
            'organization_id' => $org->id,
            'restaurant_id' => $restaurant->id,
            'timezone' => 'Africa/Cairo',
        ]);

        $this->handler = new OrderHandler;
    }

    public function test_insert_order_computes_cogs_discounts_local_time_and_weekday(): void
    {
        // 2026-10-31 is a Saturday -> Weekday::of() is 0
        $document = [
            'type' => 'order',
            'uuid' => '01930f2a-6c1e-7b4a-9c3e-2f1d8a7e5b10',
            'version' => 100,
            'op' => 'upsert',
            'occurred_at' => '2026-10-31T20:00:00+02:00',
            'data' => [
                'number' => 'ORD-101',
                'shift_uuid' => '01930f2a-6c1e-7b4a-9c3e-2f1d8a7e5b01',
                'business_date' => '2026-10-31',
                'type' => 'dine_in',
                'status' => 'completed',
                'payment_status' => 'paid',
                'return_status' => null,
                'created_at' => '2026-10-31T21:15:00+03:00', // Africa/Cairo is UTC+2 or UTC+3
                'cashier_uuid' => '01930f2a-6c1e-7b4a-9c3e-2f1d8a7e5b02',
                'driver_uuid' => null,
                'customer_hash' => 'hash_abc123',
                'region' => 'Downtown',
                'sub_total' => '250.00',
                'discount' => '25.00',
                'tax' => '31.50',
                'service' => '25.00',
                'total' => '281.50',
                'profit' => '90.00',
                'web_pos_diff' => '0.00',
                'items' => [
                    [
                        'uuid' => '01930f2a-item-1',
                        'product_uuid' => '01930f2a-prod-1',
                        'product_ref' => 'REF-01',
                        'quantity' => '2.000',
                        'price' => '100.00',
                        'unit_cost' => '35.00',
                        'item_discount' => '10.00',
                        'item_discount_type' => 'fixed',
                        'total' => '190.00',
                    ],
                    [
                        'uuid' => '01930f2a-item-2',
                        'product_uuid' => '01930f2a-prod-2',
                        'product_ref' => 'REF-02',
                        'quantity' => '1.000',
                        'price' => '50.00',
                        'unit_cost' => '20.00',
                        'item_discount' => '5.00',
                        'item_discount_type' => 'fixed',
                        'total' => '45.00',
                    ],
                ],
                'payments' => [
                    [
                        'method' => 'cash',
                        'amount' => '281.50',
                        'shift_uuid' => '01930f2a-6c1e-7b4a-9c3e-2f1d8a7e5b01',
                        'paid_at' => '2026-10-31T21:30:00+03:00',
                    ],
                ],
            ],
        ];

        $result = $this->handler->handle($this->branch, $document);

        $this->assertTrue($result->applied);
        $this->assertSame(['2026-10-31'], $result->dirtyDates);
        $this->assertFalse($result->dayClosed);

        $order = WhOrder::where('uuid', $document['uuid'])->first();
        $this->assertNotNull($order);
        $this->assertSame($this->branch->organization_id, $order->organization_id);
        $this->assertSame($this->branch->id, $order->branch_id);
        $this->assertSame(100, $order->source_version);
        $this->assertSame('2026-10-31', $order->business_date->format('Y-m-d'));
        $this->assertSame('ORD-101', $order->number);
        $this->assertSame('completed', $order->status);
        $this->assertSame('90.00', (string) $order->profit_pos);
        $this->assertSame('0.00', (string) $order->web_pos_diff);

        // COGS: 2 * 35 + 1 * 20 = 90.00
        $this->assertSame('90.00', (string) $order->cogs);
        // items_discount: 10 + 5 = 15.00
        $this->assertSame('15.00', (string) $order->items_discount);

        // Saturday => 0
        $this->assertSame(0, $order->weekday);

        // Local hour in Cairo
        $expectedCairo = Carbon::parse('2026-10-31T21:15:00+03:00')->setTimezone('Africa/Cairo');
        $this->assertSame($expectedCairo->hour, $order->hour_of_day);
        $this->assertSame($expectedCairo->format('Y-m-d H:i:s'), $order->created_at_local->format('Y-m-d H:i:s'));

        // Child rows
        $this->assertCount(2, WhOrderItem::where('order_uuid', $order->uuid)->get());
        $this->assertCount(1, WhPayment::where('order_uuid', $order->uuid)->get());
    }

    public function test_update_with_higher_version_replaces_children_and_updates_parent(): void
    {
        $uuid = '01930f2a-6c1e-7b4a-9c3e-2f1d8a7e5b10';

        // Insert version 1
        $v1Doc = [
            'type' => 'order',
            'uuid' => $uuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T12:00:00+02:00',
            'data' => [
                'number' => 'ORD-1',
                'business_date' => '2026-10-29',
                'type' => 'dine_in',
                'status' => 'processing',
                'items' => [
                    [
                        'uuid' => 'old-item-1',
                        'product_uuid' => 'prod-1',
                        'quantity' => '1.000',
                        'price' => '100.00',
                        'unit_cost' => '30.00',
                        'total' => '100.00',
                    ],
                ],
                'payments' => [],
            ],
        ];
        $this->handler->handle($this->branch, $v1Doc);

        $this->assertCount(1, WhOrderItem::where('order_uuid', $uuid)->get());

        // Update with version 2 (higher version)
        $v2Doc = [
            'type' => 'order',
            'uuid' => $uuid,
            'version' => 2,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T12:30:00+02:00',
            'data' => [
                'number' => 'ORD-1',
                'business_date' => '2026-10-29',
                'type' => 'dine_in',
                'status' => 'completed',
                'items' => [
                    [
                        'uuid' => 'new-item-1',
                        'product_uuid' => 'prod-2',
                        'quantity' => '2.000',
                        'price' => '150.00',
                        'unit_cost' => '40.00',
                        'total' => '300.00',
                    ],
                    [
                        'uuid' => 'new-item-2',
                        'product_uuid' => 'prod-3',
                        'quantity' => '1.000',
                        'price' => '50.00',
                        'unit_cost' => '10.00',
                        'total' => '50.00',
                    ],
                ],
                'payments' => [
                    [
                        'method' => 'visa',
                        'amount' => '350.00',
                        'paid_at' => '2026-10-29T12:30:00+02:00',
                    ],
                ],
            ],
        ];

        $result = $this->handler->handle($this->branch, $v2Doc);

        $this->assertTrue($result->applied);

        $order = WhOrder::where('uuid', $uuid)->first();
        $this->assertNotNull($order);
        $this->assertSame(2, $order->source_version);
        $this->assertSame('completed', $order->status);

        // Children replaced
        $items = WhOrderItem::where('order_uuid', $uuid)->get();
        $this->assertCount(2, $items);
        $this->assertFalse($items->contains('uuid', 'old-item-1'));
        $this->assertTrue($items->contains('uuid', 'new-item-1'));
        $this->assertTrue($items->contains('uuid', 'new-item-2'));

        $this->assertCount(1, WhPayment::where('order_uuid', $uuid)->get());
    }

    public function test_lower_or_equal_version_is_skipped(): void
    {
        $uuid = '01930f2a-6c1e-7b4a-9c3e-2f1d8a7e5b10';

        $v10Doc = [
            'type' => 'order',
            'uuid' => $uuid,
            'version' => 10,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T12:00:00+02:00',
            'data' => [
                'number' => 'ORD-V10',
                'business_date' => '2026-10-29',
                'type' => 'takeaway',
                'status' => 'completed',
            ],
        ];
        $this->handler->handle($this->branch, $v10Doc);

        // Equal version 10
        $resultEqual = $this->handler->handle($this->branch, [
            'type' => 'order',
            'uuid' => $uuid,
            'version' => 10,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T12:00:00+02:00',
            'data' => ['number' => 'ORD-CHANGED'],
        ]);
        $this->assertFalse($resultEqual->applied);

        // Lower version 9
        $resultLower = $this->handler->handle($this->branch, [
            'type' => 'order',
            'uuid' => $uuid,
            'version' => 9,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T12:00:00+02:00',
            'data' => ['number' => 'ORD-LOWER'],
        ]);
        $this->assertFalse($resultLower->applied);

        $order = WhOrder::where('uuid', $uuid)->first();
        $this->assertSame('ORD-V10', $order->number);
        $this->assertSame(10, $order->source_version);
    }

    public function test_order_moved_to_new_business_date_returns_both_dates(): void
    {
        $uuid = '01930f2a-6c1e-7b4a-9c3e-2f1d8a7e5b10';

        $v1Doc = [
            'type' => 'order',
            'uuid' => $uuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-28T23:50:00+02:00',
            'data' => [
                'number' => 'ORD-MIDNIGHT',
                'business_date' => '2026-10-28',
                'type' => 'delivery',
                'status' => 'pending',
            ],
        ];
        $this->handler->handle($this->branch, $v1Doc);

        // Order shifted to next day's shift
        $v2Doc = [
            'type' => 'order',
            'uuid' => $uuid,
            'version' => 2,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T00:10:00+02:00',
            'data' => [
                'number' => 'ORD-MIDNIGHT',
                'business_date' => '2026-10-29',
                'type' => 'delivery',
                'status' => 'completed',
            ],
        ];

        $result = $this->handler->handle($this->branch, $v2Doc);

        $this->assertTrue($result->applied);
        $this->assertCount(2, $result->dirtyDates);
        $this->assertContains('2026-10-29', $result->dirtyDates);
        $this->assertContains('2026-10-28', $result->dirtyDates);
    }

    public function test_delete_tombstone_marks_soft_deleted_and_keeps_children(): void
    {
        $uuid = '01930f2a-6c1e-7b4a-9c3e-2f1d8a7e5b10';

        $insertDoc = [
            'type' => 'order',
            'uuid' => $uuid,
            'version' => 5,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T12:00:00+02:00',
            'data' => [
                'number' => 'ORD-DEL',
                'business_date' => '2026-10-29',
                'type' => 'dine_in',
                'status' => 'cancelled',
                'items' => [
                    [
                        'uuid' => 'del-item-1',
                        'product_uuid' => 'prod-1',
                        'quantity' => '1.000',
                        'price' => '10.00',
                        'unit_cost' => '5.00',
                        'total' => '10.00',
                    ],
                ],
            ],
        ];
        $this->handler->handle($this->branch, $insertDoc);

        $deleteDoc = [
            'type' => 'order',
            'uuid' => $uuid,
            'version' => 6,
            'op' => 'delete',
            'occurred_at' => '2026-10-29T12:05:00+02:00',
            'data' => null,
        ];

        $result = $this->handler->handle($this->branch, $deleteDoc);

        $this->assertTrue($result->applied);
        $this->assertSame(['2026-10-29'], $result->dirtyDates);

        // Soft deleted
        $this->assertNull(WhOrder::where('uuid', $uuid)->first());
        $trashed = WhOrder::withTrashed()->where('uuid', $uuid)->first();
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame(6, $trashed->source_version);

        // Children kept
        $this->assertCount(1, WhOrderItem::where('order_uuid', $uuid)->get());
    }

    public function test_delete_non_existent_returns_skipped(): void
    {
        $deleteDoc = [
            'type' => 'order',
            'uuid' => 'non-existent-uuid',
            'version' => 1,
            'op' => 'delete',
            'occurred_at' => '2026-10-29T12:05:00+02:00',
            'data' => null,
        ];

        $result = $this->handler->handle($this->branch, $deleteDoc);

        $this->assertFalse($result->applied);
        $this->assertEmpty($result->dirtyDates);
    }
}
