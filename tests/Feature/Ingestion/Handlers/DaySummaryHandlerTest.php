<?php

namespace Tests\Feature\Ingestion\Handlers;

use App\Domain\Ingestion\Handlers\DaySummaryHandler;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Warehouse\WhDaySummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DaySummaryHandlerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private DaySummaryHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create();
        $restaurant = Restaurant::factory()->create(['organization_id' => $org->id]);
        $this->branch = Branch::factory()->create([
            'organization_id' => $org->id,
            'restaurant_id' => $restaurant->id,
        ]);

        $this->handler = new DaySummaryHandler;
    }

    public function test_insert_day_summary_sets_day_closed_true(): void
    {
        $document = [
            'type' => 'day_summary',
            'uuid' => '01930f2a-sum-uuid-1',
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T23:59:59+02:00',
            'data' => [
                'date' => '2026-10-29',
                'orders_count' => 150,
                'completed_count' => 145,
                'net_total' => '25000.00',
                'discount_total' => '1500.00',
                'cogs_total' => '8000.00',
                'expenses_total' => '2000.00',
                'waste_total' => '350.00',
            ],
        ];

        $result = $this->handler->handle($this->branch, $document);

        $this->assertTrue($result->applied);
        $this->assertTrue($result->dayClosed);
        $this->assertSame(['2026-10-29'], $result->dirtyDates);

        $summary = WhDaySummary::where('uuid', $document['uuid'])->first();
        $this->assertNotNull($summary);
        $this->assertSame('2026-10-29', $summary->business_date->format('Y-m-d'));
        $this->assertSame(150, $summary->orders_count);
        $this->assertSame(145, $summary->completed_count);
        $this->assertSame('25000.00', (string) $summary->net_total);
        $this->assertSame('1500.00', (string) $summary->discount_total);
        $this->assertSame('8000.00', (string) $summary->cogs_total);
        $this->assertSame('2000.00', (string) $summary->expenses_total);
        $this->assertSame('350.00', (string) $summary->waste_total);
    }

    public function test_update_higher_version(): void
    {
        $uuid = '01930f2a-sum-uuid-2';

        $this->handler->handle($this->branch, [
            'type' => 'day_summary',
            'uuid' => $uuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T23:00:00+02:00',
            'data' => [
                'date' => '2026-10-29',
                'orders_count' => 10,
                'net_total' => '1000.00',
            ],
        ]);

        $resUpdate = $this->handler->handle($this->branch, [
            'type' => 'day_summary',
            'uuid' => $uuid,
            'version' => 2,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T23:30:00+02:00',
            'data' => [
                'date' => '2026-10-29',
                'orders_count' => 12,
                'net_total' => '1200.00',
            ],
        ]);

        $this->assertTrue($resUpdate->applied);
        $summary = WhDaySummary::where('uuid', $uuid)->first();
        $this->assertSame(2, $summary->source_version);
        $this->assertSame(12, $summary->orders_count);
        $this->assertSame('1200.00', (string) $summary->net_total);
    }

    public function test_lower_or_equal_version_skipped(): void
    {
        $uuid = '01930f2a-sum-uuid-3';

        $this->handler->handle($this->branch, [
            'type' => 'day_summary',
            'uuid' => $uuid,
            'version' => 5,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T23:00:00+02:00',
            'data' => ['date' => '2026-10-29', 'orders_count' => 5],
        ]);

        $resEqual = $this->handler->handle($this->branch, [
            'type' => 'day_summary',
            'uuid' => $uuid,
            'version' => 5,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T23:00:00+02:00',
            'data' => ['date' => '2026-10-29', 'orders_count' => 99],
        ]);
        $this->assertFalse($resEqual->applied);
    }

    public function test_delete_tombstone(): void
    {
        $uuid = '01930f2a-sum-uuid-4';

        $this->handler->handle($this->branch, [
            'type' => 'day_summary',
            'uuid' => $uuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T23:00:00+02:00',
            'data' => ['date' => '2026-10-29', 'orders_count' => 5],
        ]);

        $resDelete = $this->handler->handle($this->branch, [
            'type' => 'day_summary',
            'uuid' => $uuid,
            'version' => 2,
            'op' => 'delete',
            'occurred_at' => '2026-10-29T23:30:00+02:00',
            'data' => null,
        ]);

        $this->assertTrue($resDelete->applied);
        $this->assertSame(['2026-10-29'], $resDelete->dirtyDates);
        $trashed = WhDaySummary::withTrashed()->where('uuid', $uuid)->first();
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame(2, $trashed->source_version);
    }
}
