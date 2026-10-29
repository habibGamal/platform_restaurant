<?php

namespace Tests\Feature\Ingestion\Handlers;

use App\Domain\Ingestion\Handlers\ExpenseHandler;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Warehouse\WhExpense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseHandlerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private ExpenseHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create();
        $restaurant = Restaurant::factory()->create(['organization_id' => $org->id]);
        $this->branch = Branch::factory()->create([
            'organization_id' => $org->id,
            'restaurant_id' => $restaurant->id,
        ]);

        $this->handler = new ExpenseHandler;
    }

    public function test_insert_expense(): void
    {
        $document = [
            'type' => 'expense',
            'uuid' => '01930f2a-exp-uuid-1',
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T14:00:00+02:00',
            'data' => [
                'shift_uuid' => '01930f2a-shift-1',
                'business_date' => '2026-10-29',
                'expense_type_uuid' => '01930f2a-exptype-1',
                'amount' => '175.50',
                'notes' => 'Paper cups and packaging',
            ],
        ];

        $result = $this->handler->handle($this->branch, $document);

        $this->assertTrue($result->applied);
        $this->assertSame(['2026-10-29'], $result->dirtyDates);

        $expense = WhExpense::where('uuid', $document['uuid'])->first();
        $this->assertNotNull($expense);
        $this->assertSame('01930f2a-shift-1', $expense->shift_uuid);
        $this->assertSame('01930f2a-exptype-1', $expense->expense_type_uuid);
        $this->assertSame('175.50', (string) $expense->amount);
        $this->assertSame('Paper cups and packaging', $expense->notes);
    }

    public function test_update_higher_version(): void
    {
        $uuid = '01930f2a-exp-uuid-2';

        $this->handler->handle($this->branch, [
            'type' => 'expense',
            'uuid' => $uuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => [
                'business_date' => '2026-10-29',
                'amount' => '100.00',
                'notes' => 'Old note',
            ],
        ]);

        $resUpdate = $this->handler->handle($this->branch, [
            'type' => 'expense',
            'uuid' => $uuid,
            'version' => 2,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T11:00:00+02:00',
            'data' => [
                'business_date' => '2026-10-29',
                'amount' => '120.00',
                'notes' => 'Updated note',
            ],
        ]);

        $this->assertTrue($resUpdate->applied);
        $expense = WhExpense::where('uuid', $uuid)->first();
        $this->assertSame(2, $expense->source_version);
        $this->assertSame('120.00', (string) $expense->amount);
        $this->assertSame('Updated note', $expense->notes);
    }

    public function test_lower_or_equal_version_skipped(): void
    {
        $uuid = '01930f2a-exp-uuid-3';

        $this->handler->handle($this->branch, [
            'type' => 'expense',
            'uuid' => $uuid,
            'version' => 5,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['business_date' => '2026-10-29', 'amount' => '100.00'],
        ]);

        $resEqual = $this->handler->handle($this->branch, [
            'type' => 'expense',
            'uuid' => $uuid,
            'version' => 5,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['business_date' => '2026-10-29', 'amount' => '999.00'],
        ]);
        $this->assertFalse($resEqual->applied);

        $resLower = $this->handler->handle($this->branch, [
            'type' => 'expense',
            'uuid' => $uuid,
            'version' => 4,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['business_date' => '2026-10-29', 'amount' => '999.00'],
        ]);
        $this->assertFalse($resLower->applied);
    }

    public function test_delete_tombstone(): void
    {
        $uuid = '01930f2a-exp-uuid-4';

        $this->handler->handle($this->branch, [
            'type' => 'expense',
            'uuid' => $uuid,
            'version' => 1,
            'op' => 'upsert',
            'occurred_at' => '2026-10-29T10:00:00+02:00',
            'data' => ['business_date' => '2026-10-29', 'amount' => '100.00'],
        ]);

        $resDelete = $this->handler->handle($this->branch, [
            'type' => 'expense',
            'uuid' => $uuid,
            'version' => 2,
            'op' => 'delete',
            'occurred_at' => '2026-10-29T11:00:00+02:00',
            'data' => null,
        ]);

        $this->assertTrue($resDelete->applied);
        $this->assertSame(['2026-10-29'], $resDelete->dirtyDates);

        $trashed = WhExpense::withTrashed()->where('uuid', $uuid)->first();
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame(2, $trashed->source_version);
    }
}
