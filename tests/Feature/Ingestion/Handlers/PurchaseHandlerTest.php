<?php

namespace Tests\Feature\Ingestion\Handlers;

use App\Domain\Ingestion\Handlers\PurchaseHandler;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Warehouse\WhPurchase;
use App\Models\Warehouse\WhPurchaseItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseHandlerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create();
        $restaurant = Restaurant::factory()->create(['organization_id' => $org->id]);
        $this->branch = Branch::factory()->create([
            'organization_id' => $org->id,
            'restaurant_id' => $restaurant->id,
        ]);
    }

    public function test_insert_purchase_invoice(): void
    {
        $handler = new PurchaseHandler('purchase_invoice');

        $document = [
            'type' => 'purchase_invoice',
            'uuid' => '01930f2a-purch-uuid-1',
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T14:30:00+02:00',
            'data' => [
                'business_date' => '2026-10-29',
                'closed_at' => '2026-10-29T14:30:00+02:00',
                'supplier_uuid' => '01930f2a-supplier-1',
                'total' => '1500.00',
                'items' => [
                    [
                        'product_uuid' => '01930f2a-prod-1',
                        'quantity' => '10.000',
                        'price' => '150.00',
                        'total' => '1500.00',
                    ],
                ],
            ],
        ];

        $result = $handler->handle($this->branch, $document);

        $this->assertTrue($result->applied);
        $this->assertSame(['2026-10-29'], $result->dirtyDates);

        $purchase = WhPurchase::where('uuid', $document['uuid'])->first();
        $this->assertNotNull($purchase);
        $this->assertSame('invoice', $purchase->kind);
        $this->assertSame('1500.00', (string) $purchase->total);
        $this->assertSame('01930f2a-supplier-1', $purchase->supplier_uuid);

        $this->assertCount(1, WhPurchaseItem::where('purchase_uuid', $purchase->uuid)->get());
    }

    public function test_insert_purchase_return(): void
    {
        $handler = new PurchaseHandler('purchase_return');

        $document = [
            'type' => 'purchase_return',
            'uuid' => '01930f2a-purch-uuid-2',
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T15:00:00+02:00',
            'data' => [
                'business_date' => '2026-10-29',
                'closed_at' => '2026-10-29T15:00:00+02:00',
                'supplier_uuid' => '01930f2a-supplier-1',
                'total' => '300.00',
                'items' => [
                    [
                        'product_uuid' => '01930f2a-prod-1',
                        'quantity' => '2.000',
                        'price' => '150.00',
                        'total' => '300.00',
                    ],
                ],
            ],
        ];

        $result = $handler->handle($this->branch, $document);

        $this->assertTrue($result->applied);
        $purchase = WhPurchase::where('uuid', $document['uuid'])->first();
        $this->assertNotNull($purchase);
        $this->assertSame('return', $purchase->kind);
        $this->assertSame('300.00', (string) $purchase->total);
    }

    public function test_update_higher_version_replaces_items(): void
    {
        $handler = new PurchaseHandler('purchase_invoice');
        $uuid = '01930f2a-purch-uuid-3';

        $handler->handle($this->branch, [
            'type' => 'purchase_invoice',
            'uuid' => $uuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => [
                'business_date' => '2026-10-29',
                'total' => '100.00',
                'items' => [
                    ['product_uuid' => 'p1', 'quantity' => '1.000', 'price' => '100.00', 'total' => '100.00'],
                ],
            ],
        ]);

        $resUpdate = $handler->handle($this->branch, [
            'type' => 'purchase_invoice',
            'uuid' => $uuid,
            'version' => 2,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T11:00:00+02:00',
            'data' => [
                'business_date' => '2026-10-29',
                'total' => '250.00',
                'items' => [
                    ['product_uuid' => 'p2', 'quantity' => '5.000', 'price' => '50.00', 'total' => '250.00'],
                ],
            ],
        ]);

        $this->assertTrue($resUpdate->applied);
        $purchase = WhPurchase::where('uuid', $uuid)->first();
        $this->assertSame(2, $purchase->source_version);
        $this->assertSame('250.00', (string) $purchase->total);

        $items = WhPurchaseItem::where('purchase_uuid', $uuid)->get();
        $this->assertCount(1, $items);
        $this->assertSame('p2', $items->first()->product_uuid);
    }

    public function test_delete_tombstone(): void
    {
        $handler = new PurchaseHandler('purchase_invoice');
        $uuid = '01930f2a-purch-uuid-4';

        $handler->handle($this->branch, [
            'type' => 'purchase_invoice',
            'uuid' => $uuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['business_date' => '2026-10-29', 'total' => '50.00'],
        ]);

        $resDelete = $handler->handle($this->branch, [
            'type' => 'purchase_invoice',
            'uuid' => $uuid,
            'version' => 2,
            'op' => 'delete',
            'occurred_at' => '2026-10-29T11:00:00+02:00',
            'data' => null,
        ]);

        $this->assertTrue($resDelete->applied);
        $trashed = WhPurchase::withTrashed()->where('uuid', $uuid)->first();
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame(2, $trashed->source_version);
    }
}
