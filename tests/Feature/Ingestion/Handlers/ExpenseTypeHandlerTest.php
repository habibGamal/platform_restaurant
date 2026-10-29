<?php

namespace Tests\Feature\Ingestion\Handlers;

use App\Domain\Ingestion\Handlers\ExpenseTypeHandler;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Warehouse\DimExpenseType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseTypeHandlerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private ExpenseTypeHandler $handler;

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

        $this->handler = new ExpenseTypeHandler;
    }

    public function test_new_expense_type_returns_all_dates_of_current_and_previous_month(): void
    {
        $document = [
            'type' => 'expense_type',
            'uuid' => '01930f2a-exptype-uuid-1',
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => [
                'name' => 'Monthly Rent',
                'category' => 'rent',
                'avg_month_rate' => '15000.00',
            ],
        ];

        $result = $this->handler->handle($this->branch, $document);

        $this->assertTrue($result->applied);
        $this->assertNotEmpty($result->dirtyDates);

        // Expected dates: 1st of previous month up to today
        $today = Carbon::now('Africa/Cairo')->startOfDay();
        $start = $today->copy()->subMonthNoOverflow()->startOfMonth();
        $this->assertSame($start->format('Y-m-d'), $result->dirtyDates[0]);
        $this->assertSame($today->format('Y-m-d'), $result->dirtyDates[count($result->dirtyDates) - 1]);

        $expType = DimExpenseType::where('uuid', $document['uuid'])->first();
        $this->assertNotNull($expType);
        $this->assertSame('Monthly Rent', $expType->name);
        $this->assertSame('rent', $expType->category);
        $this->assertSame('15000.00', (string) $expType->avg_month_rate);
    }

    public function test_category_change_returns_all_dates_and_preserves_override(): void
    {
        $uuid = '01930f2a-exptype-uuid-2';

        $this->handler->handle($this->branch, [
            'type' => 'expense_type',
            'uuid' => $uuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['name' => 'Ad Campaign', 'category' => 'marketing'],
        ]);

        // Manually set category_override on platform
        DimExpenseType::where('uuid', $uuid)->update(['category_override' => 'marketing_digital']);

        // Update with category change from 'marketing' to 'other'
        $resUpdate = $this->handler->handle($this->branch, [
            'type' => 'expense_type',
            'uuid' => $uuid,
            'version' => 2,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T11:00:00+02:00',
            'data' => ['name' => 'Ad Campaign', 'category' => 'other'],
        ]);

        $this->assertTrue($resUpdate->applied);
        $this->assertNotEmpty($resUpdate->dirtyDates);

        $expType = DimExpenseType::where('uuid', $uuid)->first();
        $this->assertSame('other', $expType->category);
        $this->assertSame('marketing_digital', $expType->category_override);
    }

    public function test_no_category_change_returns_empty_dirty_dates(): void
    {
        $uuid = '01930f2a-exptype-uuid-3';

        $this->handler->handle($this->branch, [
            'type' => 'expense_type',
            'uuid' => $uuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['name' => 'Electricity', 'category' => 'utilities'],
        ]);

        // Update name and rate only, keeping category 'utilities'
        $resUpdate = $this->handler->handle($this->branch, [
            'type' => 'expense_type',
            'uuid' => $uuid,
            'version' => 2,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T11:00:00+02:00',
            'data' => ['name' => 'Electricity & Water', 'category' => 'utilities', 'avg_month_rate' => '3000.00'],
        ]);

        $this->assertTrue($resUpdate->applied);
        $this->assertSame([], $resUpdate->dirtyDates);

        $expType = DimExpenseType::where('uuid', $uuid)->first();
        $this->assertSame('Electricity & Water', $expType->name);
        $this->assertSame('3000.00', (string) $expType->avg_month_rate);
    }

    public function test_lower_or_equal_version_skipped(): void
    {
        $uuid = '01930f2a-exptype-uuid-4';

        $this->handler->handle($this->branch, [
            'type' => 'expense_type',
            'uuid' => $uuid,
            'version' => 5,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['name' => 'Cleaning', 'category' => 'maintenance'],
        ]);

        $resEqual = $this->handler->handle($this->branch, [
            'type' => 'expense_type',
            'uuid' => $uuid,
            'version' => 5,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['name' => 'Deep Cleaning', 'category' => 'other'],
        ]);
        $this->assertFalse($resEqual->applied);
    }

    public function test_delete_tombstone(): void
    {
        $uuid = '01930f2a-exptype-uuid-5';

        $this->handler->handle($this->branch, [
            'type' => 'expense_type',
            'uuid' => $uuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['name' => 'Old Type', 'category' => 'other'],
        ]);

        $resDelete = $this->handler->handle($this->branch, [
            'type' => 'expense_type',
            'uuid' => $uuid,
            'version' => 2,
            'op' => 'delete',
            'occurred_at' => '2026-10-29T11:00:00+02:00',
            'data' => null,
        ]);

        $this->assertTrue($resDelete->applied);
        $this->assertNotEmpty($resDelete->dirtyDates);
        $trashed = DimExpenseType::withTrashed()->where('uuid', $uuid)->first();
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame(2, $trashed->source_version);
    }
}
