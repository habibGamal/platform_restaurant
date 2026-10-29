<?php

namespace Tests\Feature\Ingestion\Handlers;

use App\Domain\Ingestion\Handlers\SupplierHandler;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Warehouse\DimSupplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierHandlerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private SupplierHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create();
        $restaurant = Restaurant::factory()->create(['organization_id' => $org->id]);
        $this->branch = Branch::factory()->create([
            'organization_id' => $org->id,
            'restaurant_id' => $restaurant->id,
        ]);

        $this->handler = new SupplierHandler;
    }

    public function test_insert_supplier(): void
    {
        $document = [
            'type' => 'supplier',
            'uuid' => '01930f2a-sup-uuid-1',
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => [
                'name' => 'Al-Ahram Poultry',
                'phone' => '+201001234567',
            ],
        ];

        $result = $this->handler->handle($this->branch, $document);

        $this->assertTrue($result->applied);
        $this->assertSame([], $result->dirtyDates);

        $sup = DimSupplier::where('uuid', $document['uuid'])->first();
        $this->assertNotNull($sup);
        $this->assertSame('Al-Ahram Poultry', $sup->name);
        $this->assertSame('+201001234567', $sup->phone);
    }

    public function test_update_higher_version(): void
    {
        $uuid = '01930f2a-sup-uuid-2';

        $this->handler->handle($this->branch, [
            'type' => 'supplier',
            'uuid' => $uuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['name' => 'Supplier A', 'phone' => '111'],
        ]);

        $resUpdate = $this->handler->handle($this->branch, [
            'type' => 'supplier',
            'uuid' => $uuid,
            'version' => 2,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T11:00:00+02:00',
            'data' => ['name' => 'Supplier A Updated', 'phone' => '222'],
        ]);

        $this->assertTrue($resUpdate->applied);
        $sup = DimSupplier::where('uuid', $uuid)->first();
        $this->assertSame(2, $sup->source_version);
        $this->assertSame('Supplier A Updated', $sup->name);
        $this->assertSame('222', $sup->phone);
    }

    public function test_lower_or_equal_version_skipped(): void
    {
        $uuid = '01930f2a-sup-uuid-3';

        $this->handler->handle($this->branch, [
            'type' => 'supplier',
            'uuid' => $uuid,
            'version' => 5,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['name' => 'Supplier X'],
        ]);

        $resEqual = $this->handler->handle($this->branch, [
            'type' => 'supplier',
            'uuid' => $uuid,
            'version' => 5,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['name' => 'Supplier Y'],
        ]);
        $this->assertFalse($resEqual->applied);
    }

    public function test_delete_tombstone(): void
    {
        $uuid = '01930f2a-sup-uuid-4';

        $this->handler->handle($this->branch, [
            'type' => 'supplier',
            'uuid' => $uuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['name' => 'Supplier To Delete'],
        ]);

        $resDelete = $this->handler->handle($this->branch, [
            'type' => 'supplier',
            'uuid' => $uuid,
            'version' => 2,
            'op' => 'delete',
            'occurred_at' => '2026-10-29T11:00:00+02:00',
            'data' => null,
        ]);

        $this->assertTrue($resDelete->applied);
        $trashed = DimSupplier::withTrashed()->where('uuid', $uuid)->first();
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame(2, $trashed->source_version);
    }
}
