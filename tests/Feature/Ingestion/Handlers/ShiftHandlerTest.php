<?php

namespace Tests\Feature\Ingestion\Handlers;

use App\Domain\Ingestion\Handlers\ShiftHandler;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Warehouse\WhShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftHandlerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private ShiftHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create();
        $restaurant = Restaurant::factory()->create(['organization_id' => $org->id]);
        $this->branch = Branch::factory()->create([
            'organization_id' => $org->id,
            'restaurant_id' => $restaurant->id,
        ]);

        $this->handler = new ShiftHandler;
    }

    public function test_insert_shift(): void
    {
        $document = [
            'type' => 'shift',
            'uuid' => '01930f2a-shift-uuid-1',
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T16:00:00+02:00',
            'data' => [
                'business_date' => '2026-10-29',
                'user_uuid' => '01930f2a-user-1',
                'start_at' => '2026-10-29T08:00:00+02:00',
                'end_at' => '2026-10-29T16:00:00+02:00',
                'start_cash' => '1000.00',
                'end_cash' => '2500.00',
                'real_cash' => '2490.00',
                'losses_amount' => '10.00',
                'has_deficit' => true,
                'closed' => true,
            ],
        ];

        $result = $this->handler->handle($this->branch, $document);

        $this->assertTrue($result->applied);
        $this->assertSame(['2026-10-29'], $result->dirtyDates);

        $shift = WhShift::where('uuid', $document['uuid'])->first();
        $this->assertNotNull($shift);
        $this->assertSame('01930f2a-user-1', $shift->user_uuid);
        $this->assertSame('1000.00', (string) $shift->start_cash);
        $this->assertSame('2500.00', (string) $shift->end_cash);
        $this->assertSame('2490.00', (string) $shift->real_cash);
        $this->assertSame('10.00', (string) $shift->losses_amount);
        $this->assertTrue($shift->has_deficit);
        $this->assertTrue($shift->closed);
    }

    public function test_update_higher_version(): void
    {
        $uuid = '01930f2a-shift-uuid-2';

        $this->handler->handle($this->branch, [
            'type' => 'shift',
            'uuid' => $uuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T08:00:00+02:00',
            'data' => [
                'business_date' => '2026-10-29',
                'start_cash' => '500.00',
                'closed' => false,
            ],
        ]);

        $resUpdate = $this->handler->handle($this->branch, [
            'type' => 'shift',
            'uuid' => $uuid,
            'version' => 2,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T16:00:00+02:00',
            'data' => [
                'business_date' => '2026-10-29',
                'start_cash' => '500.00',
                'end_cash' => '1200.00',
                'closed' => true,
            ],
        ]);

        $this->assertTrue($resUpdate->applied);
        $shift = WhShift::where('uuid', $uuid)->first();
        $this->assertSame(2, $shift->source_version);
        $this->assertTrue($shift->closed);
        $this->assertSame('1200.00', (string) $shift->end_cash);
    }

    public function test_lower_or_equal_version_skipped(): void
    {
        $uuid = '01930f2a-shift-uuid-3';

        $this->handler->handle($this->branch, [
            'type' => 'shift',
            'uuid' => $uuid,
            'version' => 5,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T08:00:00+02:00',
            'data' => ['business_date' => '2026-10-29', 'start_cash' => '100.00'],
        ]);

        $resEqual = $this->handler->handle($this->branch, [
            'type' => 'shift',
            'uuid' => $uuid,
            'version' => 5,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T08:00:00+02:00',
            'data' => ['business_date' => '2026-10-29', 'start_cash' => '999.00'],
        ]);
        $this->assertFalse($resEqual->applied);

        $resLower = $this->handler->handle($this->branch, [
            'type' => 'shift',
            'uuid' => $uuid,
            'version' => 4,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T08:00:00+02:00',
            'data' => ['business_date' => '2026-10-29', 'start_cash' => '999.00'],
        ]);
        $this->assertFalse($resLower->applied);
    }

    public function test_delete_tombstone(): void
    {
        $uuid = '01930f2a-shift-uuid-4';

        $this->handler->handle($this->branch, [
            'type' => 'shift',
            'uuid' => $uuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T08:00:00+02:00',
            'data' => ['business_date' => '2026-10-29', 'start_cash' => '100.00'],
        ]);

        $resDelete = $this->handler->handle($this->branch, [
            'type' => 'shift',
            'uuid' => $uuid,
            'version' => 2,
            'op' => 'delete',
            'occurred_at' => '2026-10-29T09:00:00+02:00',
            'data' => null,
        ]);

        $this->assertTrue($resDelete->applied);
        $this->assertSame(['2026-10-29'], $resDelete->dirtyDates);

        $trashed = WhShift::withTrashed()->where('uuid', $uuid)->first();
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame(2, $trashed->source_version);
    }
}
