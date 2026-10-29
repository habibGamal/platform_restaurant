<?php

namespace Tests\Feature\Ingestion\Handlers;

use App\Domain\Ingestion\Handlers\RecipeHandler;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Warehouse\DimRecipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipeHandlerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private RecipeHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create();
        $restaurant = Restaurant::factory()->create(['organization_id' => $org->id]);
        $this->branch = Branch::factory()->create([
            'organization_id' => $org->id,
            'restaurant_id' => $restaurant->id,
        ]);

        $this->handler = new RecipeHandler;
    }

    public function test_insert_recipe_inserts_component_rows(): void
    {
        $productUuid = '01930f2a-prod-uuid-1';

        $document = [
            'type' => 'recipe',
            'uuid' => $productUuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => [
                'product_uuid' => $productUuid,
                'components' => [
                    [
                        'component_uuid' => '01930f2a-comp-1',
                        'quantity' => '0.2500',
                    ],
                    [
                        'component_uuid' => '01930f2a-comp-2',
                        'quantity' => '1.5000',
                    ],
                ],
            ],
        ];

        $result = $this->handler->handle($this->branch, $document);

        $this->assertTrue($result->applied);
        $this->assertSame([], $result->dirtyDates);

        $rows = DimRecipe::where('branch_id', $this->branch->id)
            ->where('product_uuid', $productUuid)
            ->get();

        $this->assertCount(2, $rows);
        $this->assertTrue($rows->contains('component_uuid', '01930f2a-comp-1'));
        $this->assertTrue($rows->contains('component_uuid', '01930f2a-comp-2'));
        $this->assertSame(1, $rows->first()->source_version);
    }

    public function test_update_higher_version_replaces_all_components(): void
    {
        $productUuid = '01930f2a-prod-uuid-2';

        $this->handler->handle($this->branch, [
            'type' => 'recipe',
            'uuid' => $productUuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => [
                'product_uuid' => $productUuid,
                'components' => [
                    ['component_uuid' => 'old-comp-1', 'quantity' => '1.0000'],
                    ['component_uuid' => 'old-comp-2', 'quantity' => '2.0000'],
                ],
            ],
        ]);

        $resUpdate = $this->handler->handle($this->branch, [
            'type' => 'recipe',
            'uuid' => $productUuid,
            'version' => 2,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T11:00:00+02:00',
            'data' => [
                'product_uuid' => $productUuid,
                'components' => [
                    ['component_uuid' => 'new-comp-1', 'quantity' => '0.5000'],
                ],
            ],
        ]);

        $this->assertTrue($resUpdate->applied);
        $rows = DimRecipe::where('branch_id', $this->branch->id)
            ->where('product_uuid', $productUuid)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame('new-comp-1', $rows->first()->component_uuid);
        $this->assertSame('0.5000', (string) $rows->first()->quantity);
        $this->assertSame(2, $rows->first()->source_version);
    }

    public function test_lower_or_equal_version_skipped(): void
    {
        $productUuid = '01930f2a-prod-uuid-3';

        $this->handler->handle($this->branch, [
            'type' => 'recipe',
            'uuid' => $productUuid,
            'version' => 10,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => [
                'product_uuid' => $productUuid,
                'components' => [
                    ['component_uuid' => 'c1', 'quantity' => '1.0000'],
                ],
            ],
        ]);

        $resEqual = $this->handler->handle($this->branch, [
            'type' => 'recipe',
            'uuid' => $productUuid,
            'version' => 10,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => [
                'product_uuid' => $productUuid,
                'components' => [],
            ],
        ]);
        $this->assertFalse($resEqual->applied);

        $resLower = $this->handler->handle($this->branch, [
            'type' => 'recipe',
            'uuid' => $productUuid,
            'version' => 9,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => [
                'product_uuid' => $productUuid,
                'components' => [],
            ],
        ]);
        $this->assertFalse($resLower->applied);
    }

    public function test_delete_deletes_all_components_when_version_is_newer(): void
    {
        $productUuid = '01930f2a-prod-uuid-4';

        $this->handler->handle($this->branch, [
            'type' => 'recipe',
            'uuid' => $productUuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => [
                'product_uuid' => $productUuid,
                'components' => [
                    ['component_uuid' => 'comp-1', 'quantity' => '1.0000'],
                ],
            ],
        ]);

        $resDelete = $this->handler->handle($this->branch, [
            'type' => 'recipe',
            'uuid' => $productUuid,
            'version' => 2,
            'op' => 'delete',
            'occurred_at' => '2026-10-29T11:00:00+02:00',
            'data' => null,
        ]);

        $this->assertTrue($resDelete->applied);
        $this->assertCount(0, DimRecipe::where('branch_id', $this->branch->id)->where('product_uuid', $productUuid)->get());
    }

    public function test_delete_non_existent_returns_skipped(): void
    {
        $resDelete = $this->handler->handle($this->branch, [
            'type' => 'recipe',
            'uuid' => 'non-existent-product',
            'version' => 1,
            'op' => 'delete',
            'occurred_at' => '2026-10-29T11:00:00+02:00',
            'data' => null,
        ]);

        $this->assertFalse($resDelete->applied);
    }
}
