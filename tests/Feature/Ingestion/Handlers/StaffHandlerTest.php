<?php

namespace Tests\Feature\Ingestion\Handlers;

use App\Domain\Ingestion\Handlers\StaffHandler;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Warehouse\DimStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffHandlerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private StaffHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create();
        $restaurant = Restaurant::factory()->create(['organization_id' => $org->id]);
        $this->branch = Branch::factory()->create([
            'organization_id' => $org->id,
            'restaurant_id' => $restaurant->id,
        ]);

        $this->handler = new StaffHandler;
    }

    public function test_insert_staff(): void
    {
        $document = [
            'type' => 'staff',
            'uuid' => '01930f2a-staff-uuid-1',
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => [
                'name' => 'Mohamed Ali',
                'role' => 'cashier',
            ],
        ];

        $result = $this->handler->handle($this->branch, $document);

        $this->assertTrue($result->applied);
        $this->assertSame([], $result->dirtyDates);

        $staff = DimStaff::where('uuid', $document['uuid'])->first();
        $this->assertNotNull($staff);
        $this->assertSame('Mohamed Ali', $staff->name);
        $this->assertSame('cashier', $staff->role);
    }

    public function test_update_higher_version(): void
    {
        $uuid = '01930f2a-staff-uuid-2';

        $this->handler->handle($this->branch, [
            'type' => 'staff',
            'uuid' => $uuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['name' => 'Tarek', 'role' => 'waiter'],
        ]);

        $resUpdate = $this->handler->handle($this->branch, [
            'type' => 'staff',
            'uuid' => $uuid,
            'version' => 2,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T11:00:00+02:00',
            'data' => ['name' => 'Tarek Ahmed', 'role' => 'head_waiter'],
        ]);

        $this->assertTrue($resUpdate->applied);
        $staff = DimStaff::where('uuid', $uuid)->first();
        $this->assertSame(2, $staff->source_version);
        $this->assertSame('Tarek Ahmed', $staff->name);
        $this->assertSame('head_waiter', $staff->role);
    }

    public function test_lower_or_equal_version_skipped(): void
    {
        $uuid = '01930f2a-staff-uuid-3';

        $this->handler->handle($this->branch, [
            'type' => 'staff',
            'uuid' => $uuid,
            'version' => 5,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['name' => 'Ahmed', 'role' => 'cook'],
        ]);

        $resEqual = $this->handler->handle($this->branch, [
            'type' => 'staff',
            'uuid' => $uuid,
            'version' => 5,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['name' => 'Ahmed K', 'role' => 'chef'],
        ]);
        $this->assertFalse($resEqual->applied);
    }

    public function test_delete_tombstone(): void
    {
        $uuid = '01930f2a-staff-uuid-4';

        $this->handler->handle($this->branch, [
            'type' => 'staff',
            'uuid' => $uuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['name' => 'Staff To Delete', 'role' => 'cleaner'],
        ]);

        $resDelete = $this->handler->handle($this->branch, [
            'type' => 'staff',
            'uuid' => $uuid,
            'version' => 2,
            'op' => 'delete',
            'occurred_at' => '2026-10-29T11:00:00+02:00',
            'data' => null,
        ]);

        $this->assertTrue($resDelete->applied);
        $trashed = DimStaff::withTrashed()->where('uuid', $uuid)->first();
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame(2, $trashed->source_version);
    }
}
