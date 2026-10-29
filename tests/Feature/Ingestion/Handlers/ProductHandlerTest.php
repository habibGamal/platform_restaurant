<?php

namespace Tests\Feature\Ingestion\Handlers;

use App\Domain\Ingestion\Handlers\ProductHandler;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Warehouse\DimProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductHandlerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private ProductHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create();
        $restaurant = Restaurant::factory()->create(['organization_id' => $org->id]);
        $this->branch = Branch::factory()->create([
            'organization_id' => $org->id,
            'restaurant_id' => $restaurant->id,
        ]);

        $this->handler = new ProductHandler;
    }

    public function test_insert_product(): void
    {
        $document = [
            'type' => 'product',
            'uuid' => '01930f2a-prod-uuid-1',
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => [
                'name' => 'Chicken Shawarma',
                'product_ref' => 'REF-SHAW-01',
                'category_uuid' => '01930f2a-cat-1',
                'type' => 'manufactured',
                'unit' => 'sandwich',
                'price' => '85.00',
                'cost' => '35.00',
                'legacy' => false,
            ],
        ];

        $result = $this->handler->handle($this->branch, $document);

        $this->assertTrue($result->applied);
        $this->assertSame([], $result->dirtyDates);

        $product = DimProduct::where('uuid', $document['uuid'])->first();
        $this->assertNotNull($product);
        $this->assertSame('Chicken Shawarma', $product->name);
        $this->assertSame('REF-SHAW-01', $product->product_ref);
        $this->assertSame('01930f2a-cat-1', $product->category_uuid);
        $this->assertSame('manufactured', $product->type);
        $this->assertSame('sandwich', $product->unit);
        $this->assertSame('85.00', (string) $product->price);
        $this->assertSame('35.00', (string) $product->cost);
        $this->assertFalse($product->legacy);
    }

    public function test_update_higher_version(): void
    {
        $uuid = '01930f2a-prod-uuid-2';

        $this->handler->handle($this->branch, [
            'type' => 'product',
            'uuid' => $uuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['name' => 'Burger', 'price' => '70.00', 'cost' => '30.00'],
        ]);

        $resUpdate = $this->handler->handle($this->branch, [
            'type' => 'product',
            'uuid' => $uuid,
            'version' => 2,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T11:00:00+02:00',
            'data' => ['name' => 'Double Burger', 'price' => '100.00', 'cost' => '45.00'],
        ]);

        $this->assertTrue($resUpdate->applied);
        $product = DimProduct::where('uuid', $uuid)->first();
        $this->assertSame(2, $product->source_version);
        $this->assertSame('Double Burger', $product->name);
        $this->assertSame('100.00', (string) $product->price);
    }

    public function test_lower_or_equal_version_skipped(): void
    {
        $uuid = '01930f2a-prod-uuid-3';

        $this->handler->handle($this->branch, [
            'type' => 'product',
            'uuid' => $uuid,
            'version' => 5,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['name' => 'Fries', 'price' => '30.00'],
        ]);

        $resEqual = $this->handler->handle($this->branch, [
            'type' => 'product',
            'uuid' => $uuid,
            'version' => 5,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['name' => 'Curly Fries', 'price' => '45.00'],
        ]);
        $this->assertFalse($resEqual->applied);

        $resLower = $this->handler->handle($this->branch, [
            'type' => 'product',
            'uuid' => $uuid,
            'version' => 4,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['name' => 'Curly Fries', 'price' => '45.00'],
        ]);
        $this->assertFalse($resLower->applied);
    }

    public function test_delete_tombstone(): void
    {
        $uuid = '01930f2a-prod-uuid-4';

        $this->handler->handle($this->branch, [
            'type' => 'product',
            'uuid' => $uuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['name' => 'Soda', 'price' => '20.00'],
        ]);

        $resDelete = $this->handler->handle($this->branch, [
            'type' => 'product',
            'uuid' => $uuid,
            'version' => 2,
            'op' => 'delete',
            'occurred_at' => '2026-10-29T11:00:00+02:00',
            'data' => null,
        ]);

        $this->assertTrue($resDelete->applied);
        $this->assertSame([], $resDelete->dirtyDates);
        $trashed = DimProduct::withTrashed()->where('uuid', $uuid)->first();
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame(2, $trashed->source_version);
    }
}
