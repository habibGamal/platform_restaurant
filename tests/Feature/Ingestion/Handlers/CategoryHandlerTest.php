<?php

namespace Tests\Feature\Ingestion\Handlers;

use App\Domain\Ingestion\Handlers\CategoryHandler;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Warehouse\DimCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryHandlerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private CategoryHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create();
        $restaurant = Restaurant::factory()->create(['organization_id' => $org->id]);
        $this->branch = Branch::factory()->create([
            'organization_id' => $org->id,
            'restaurant_id' => $restaurant->id,
        ]);

        $this->handler = new CategoryHandler;
    }

    public function test_insert_category(): void
    {
        $document = [
            'type' => 'category',
            'uuid' => '01930f2a-cat-uuid-1',
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => [
                'name' => 'Hot Drinks',
            ],
        ];

        $result = $this->handler->handle($this->branch, $document);

        $this->assertTrue($result->applied);
        $this->assertSame([], $result->dirtyDates);

        $cat = DimCategory::where('uuid', $document['uuid'])->first();
        $this->assertNotNull($cat);
        $this->assertSame('Hot Drinks', $cat->name);
    }

    public function test_update_higher_version(): void
    {
        $uuid = '01930f2a-cat-uuid-2';

        $this->handler->handle($this->branch, [
            'type' => 'category',
            'uuid' => $uuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['name' => 'Cold Drinks'],
        ]);

        $resUpdate = $this->handler->handle($this->branch, [
            'type' => 'category',
            'uuid' => $uuid,
            'version' => 2,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T11:00:00+02:00',
            'data' => ['name' => 'Beverages & Smoothies'],
        ]);

        $this->assertTrue($resUpdate->applied);
        $cat = DimCategory::where('uuid', $uuid)->first();
        $this->assertSame(2, $cat->source_version);
        $this->assertSame('Beverages & Smoothies', $cat->name);
    }

    public function test_lower_or_equal_version_skipped(): void
    {
        $uuid = '01930f2a-cat-uuid-3';

        $this->handler->handle($this->branch, [
            'type' => 'category',
            'uuid' => $uuid,
            'version' => 5,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['name' => 'Desserts'],
        ]);

        $resEqual = $this->handler->handle($this->branch, [
            'type' => 'category',
            'uuid' => $uuid,
            'version' => 5,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['name' => 'Sweets'],
        ]);
        $this->assertFalse($resEqual->applied);
    }

    public function test_delete_tombstone(): void
    {
        $uuid = '01930f2a-cat-uuid-4';

        $this->handler->handle($this->branch, [
            'type' => 'category',
            'uuid' => $uuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['name' => 'Appetizers'],
        ]);

        $resDelete = $this->handler->handle($this->branch, [
            'type' => 'category',
            'uuid' => $uuid,
            'version' => 2,
            'op' => 'delete',
            'occurred_at' => '2026-10-29T11:00:00+02:00',
            'data' => null,
        ]);

        $this->assertTrue($resDelete->applied);
        $trashed = DimCategory::withTrashed()->where('uuid', $uuid)->first();
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame(2, $trashed->source_version);
    }
}
